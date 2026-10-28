import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { graphql } from '@/api/graphql'
import { rest, unwrap } from '@/api/rest'
import { refreshTokens } from '@/api/refresh'
import { onUnauthenticated } from '@/api/session'
import { getRefreshToken, getToken, setTokens } from '@/api/token'

// openapi-fetch captures `fetch` when the client is created, so stub it before any import runs.
const fetchMock = vi.hoisted(() => {
  const mock = vi.fn<typeof fetch>()
  globalThis.fetch = mock
  return mock
})

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json',
    },
  })

const unauthenticated = () =>
  json({ type: 'about:blank', title: 'Unauthenticated', status: 401 }, 401)

const user = { id: 1, name: 'Demo User', email: 'demo@gather.test' }
const pair = (n: number) => ({
  token: `access-${n}`,
  token_type: 'Bearer',
  expires_at: '2026-11-01T19:00:00Z',
  refresh_token: `refresh-${n}`,
  refresh_token_expires_at: '2026-12-01T18:00:00Z',
  user,
})

/** What each call sent: its URL and Authorization header, whichever way fetch was called. */
function calls(): { url: string; auth: string | null }[] {
  return fetchMock.mock.calls.map(([input, init]) => {
    const request = input instanceof Request ? input : new Request(input, init)
    return { url: new URL(request.url).pathname, auth: request.headers.get('Authorization') }
  })
}

/** A promise plus the function that settles it, to hold a response back. */
function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((r) => (resolve = r))
  return { promise, resolve }
}

const lastCall = () => calls()[calls().length - 1]

/** The part of `navigator.locks` the refresh uses; jsdom has no Web Locks. */
type LockRequest = (name: string, callback: () => unknown) => Promise<unknown>

const refreshCalls = () => calls().filter((call) => call.url.endsWith('/auth/refresh'))

/**
 * Answers like the API would: the refresh endpoint swaps refresh-1 for pair 2 (once),
 * and everything else accepts only the newest access token.
 */
function fakeApi(): void {
  let refreshed = false
  fetchMock.mockImplementation(async (input, init) => {
    const request = input instanceof Request ? input : new Request(input, init)
    if (request.url.endsWith('/auth/refresh')) {
      const { refresh_token } = (await request.json()) as { refresh_token: string }
      if (refresh_token !== 'refresh-1' || refreshed) return unauthenticated()
      refreshed = true
      return json(pair(2))
    }
    if (request.headers.get('Authorization') !== 'Bearer access-2') return unauthenticated()
    return request.url.endsWith('/graphql')
      ? json({ data: { me: { name: user.name } } })
      : json({ data: user })
  })
}

describe('token refresh', () => {
  let unregister: () => void
  const expired = vi.fn<() => void>()

  beforeEach(() => {
    setTokens(pair(1))
    unregister = onUnauthenticated(expired)
  })

  afterEach(() => {
    unregister()
    expired.mockReset()
    fetchMock.mockReset()
    vi.restoreAllMocks()
    Reflect.deleteProperty(navigator, 'locks')
  })

  it('REST refreshes an expired access token and retries the request once', async () => {
    fakeApi()

    await expect(unwrap(rest.GET('/me'))).resolves.toEqual({ data: user })

    expect(calls()).toEqual([
      { url: '/api/v1/me', auth: 'Bearer access-1' },
      { url: '/api/v1/auth/refresh', auth: null },
      { url: '/api/v1/me', auth: 'Bearer access-2' },
    ])
    expect(getToken()).toBe('access-2')
    expect(getRefreshToken()).toBe('refresh-2')
    expect(expired).not.toHaveBeenCalled()
  })

  it('shares one refresh between concurrent requests, since refresh tokens are single use', async () => {
    fakeApi()

    await Promise.all([unwrap(rest.GET('/me')), unwrap(rest.GET('/me')), unwrap(rest.GET('/me'))])

    expect(calls().filter((call) => call.url.endsWith('/auth/refresh'))).toHaveLength(1)
    expect(expired).not.toHaveBeenCalled()
  })

  it('GraphQL refreshes and runs the operation again', async () => {
    fakeApi()

    await expect(graphql('query Me { me { name } }')).resolves.toEqual({ me: { name: user.name } })
    expect(calls().map((call) => call.auth)).toEqual(['Bearer access-1', null, 'Bearer access-2'])
  })

  it('ends the session when the refresh token is refused', async () => {
    fetchMock.mockImplementation(async () => unauthenticated())

    await expect(unwrap(rest.GET('/me'))).rejects.toMatchObject({ status: 401 })

    expect(expired).toHaveBeenCalledOnce()
    expect(getRefreshToken()).toBeNull()
  })

  it('keeps the refresh token when the API is unreachable', async () => {
    fetchMock.mockImplementation(async (input) =>
      String(input instanceof Request ? input.url : input).endsWith('/auth/refresh')
        ? Promise.reject(new TypeError('Failed to fetch'))
        : unauthenticated(),
    )

    await expect(unwrap(rest.GET('/me'))).rejects.toMatchObject({ code: 'network_error' })
    expect(getRefreshToken()).toBe('refresh-1')
    expect(getToken()).toBe('access-1')
    expect(expired).not.toHaveBeenCalled()
  })

  it('treats a 200 from the refresh endpoint that is not a token pair as unavailable', async () => {
    fetchMock.mockImplementation(async (input) =>
      String(input instanceof Request ? input.url : input).endsWith('/auth/refresh')
        ? new Response('<!doctype html>', { status: 200, headers: { 'Content-Type': 'text/html' } })
        : unauthenticated(),
    )

    await expect(refreshTokens()).resolves.toBe('unavailable')
    expect(getRefreshToken()).toBe('refresh-1')
  })

  it('GraphQL keeps the session when the refresh endpoint is unreachable', async () => {
    fetchMock.mockImplementation(async (input) =>
      String(input instanceof Request ? input.url : input).endsWith('/auth/refresh')
        ? Promise.reject(new TypeError('Failed to fetch'))
        : json({ errors: [{ message: 'Unauthenticated.', extensions: { guards: ['sanctum'] } }] }),
    )

    await expect(graphql('query Me { me { name } }')).rejects.toMatchObject({
      code: 'network_error',
    })
    expect(getRefreshToken()).toBe('refresh-1')
    expect(expired).not.toHaveBeenCalled()
  })

  describe('a 401 that arrives after the token was already refreshed', () => {
    it('REST replays with the new token instead of rotating the pair again', async () => {
      fakeApi()
      const late = deferred<Response>()
      fetchMock.mockImplementationOnce(() => late.promise)

      // Sent with access-1; its 401 is held back until another request's refresh is done.
      const request = unwrap(rest.GET('/me'))
      await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledOnce())
      await expect(refreshTokens()).resolves.toBe('refreshed')
      late.resolve(unauthenticated())

      await expect(request).resolves.toEqual({ data: user })
      expect(refreshCalls()).toHaveLength(1)
      expect(lastCall()).toEqual({ url: '/api/v1/me', auth: 'Bearer access-2' })
      expect(getRefreshToken()).toBe('refresh-2')
      expect(expired).not.toHaveBeenCalled()
    })

    it('GraphQL retries with the new token instead of rotating the pair again', async () => {
      fakeApi()
      const late = deferred<Response>()
      fetchMock.mockImplementationOnce(() => late.promise)

      const operation = graphql('query Me { me { name } }')
      await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledOnce())
      await expect(refreshTokens()).resolves.toBe('refreshed')
      late.resolve(unauthenticated())

      await expect(operation).resolves.toEqual({ me: { name: user.name } })
      expect(refreshCalls()).toHaveLength(1)
      expect(lastCall()?.auth).toBe('Bearer access-2')
      expect(expired).not.toHaveBeenCalled()
    })
  })

  describe('across tabs', () => {
    it('uses the pair another tab stored instead of a copy cached at load', async () => {
      fakeApi()
      // Another tab refreshed: it wrote pair 2 straight to the shared storage.
      localStorage.setItem('gather.token', 'access-2')
      localStorage.setItem('gather.refreshToken', 'refresh-2')

      await expect(unwrap(rest.GET('/me'))).resolves.toEqual({ data: user })
      expect(calls()).toEqual([{ url: '/api/v1/me', auth: 'Bearer access-2' }])
    })

    it('refreshes inside a lock shared by all tabs', async () => {
      fakeApi()
      const request = vi.fn<LockRequest>((_name, callback) => Promise.resolve(callback()))
      Object.defineProperty(navigator, 'locks', { value: { request }, configurable: true })

      await expect(refreshTokens()).resolves.toBe('refreshed')
      expect(request).toHaveBeenCalledWith('gather-token-refresh', expect.any(Function))
      expect(getRefreshToken()).toBe('refresh-2')
    })

    it('does not reuse a refresh token another tab rotated while this one waited', async () => {
      fakeApi()
      // The other tab holds the lock, rotates the pair, then lets this tab in.
      const request = vi.fn<LockRequest>(async (_name, callback) => {
        localStorage.setItem('gather.token', 'access-2')
        localStorage.setItem('gather.refreshToken', 'refresh-2')
        return callback()
      })
      Object.defineProperty(navigator, 'locks', { value: { request }, configurable: true })

      await expect(unwrap(rest.GET('/me'))).resolves.toEqual({ data: user })
      expect(refreshCalls()).toHaveLength(0)
      expect(lastCall()).toEqual({ url: '/api/v1/me', auth: 'Bearer access-2' })
      expect(expired).not.toHaveBeenCalled()
    })

    it('keeps the tokens in memory when storage is unavailable', () => {
      vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new DOMException('denied', 'SecurityError')
      })
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('denied', 'SecurityError')
      })

      setTokens(pair(3))
      expect(getToken()).toBe('access-3')
      expect(getRefreshToken()).toBe('refresh-3')
    })
  })
})
