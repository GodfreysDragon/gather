import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { graphql } from '@/api/graphql'
import { rest, unwrap } from '@/api/rest'
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

    await expect(unwrap(rest.GET('/me'))).rejects.toMatchObject({ status: 401 })
    expect(getRefreshToken()).toBe('refresh-1')
  })
})
