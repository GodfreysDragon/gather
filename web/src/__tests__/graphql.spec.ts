import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiLog, clearApiLog } from '@/api/apiLog'
import { ApiError } from '@/api/errors'
import { graphql } from '@/api/graphql'
import { onUnauthenticated } from '@/api/session'
import { setToken } from '@/api/token'

const fetchMock = vi.fn<typeof fetch>()

function respond(body: string, status: number, contentType = 'application/json'): void {
  fetchMock.mockResolvedValueOnce(
    new Response(body, { status, headers: { 'Content-Type': contentType } }),
  )
}

async function failure(): Promise<ApiError> {
  const error = await graphql('query GetEvent { event(id: 1) { id } }').catch((e: unknown) => e)
  expect(error).toBeInstanceOf(ApiError)
  return error as ApiError
}

describe('graphql client', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock)
    clearApiLog()
    setToken(null)
  })

  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it('returns data and logs the call', async () => {
    respond(JSON.stringify({ data: { event: { id: '1' } } }), 200)

    await expect(graphql('query GetEvent { event(id: 1) { id } }')).resolves.toEqual({
      event: { id: '1' },
    })
    expect(apiLog.entries[0]).toMatchObject({
      transport: 'GraphQL',
      label: 'GetEvent',
      status: 200,
    })
  })

  it('turns a non-JSON 502 into a server_error instead of a JSON parse error', async () => {
    respond('<html><body>Bad Gateway</body></html>', 502, 'text/html')

    const error = await failure()
    expect(error.code).toBe('server_error')
    expect(error.status).toBe(502)
    expect(apiLog.entries[0]).toMatchObject({ label: 'GetEvent', status: 502 })
  })

  it('maps 429 to rate_limited', async () => {
    respond(JSON.stringify({ message: 'Too Many Attempts.' }), 429)

    const error = await failure()
    expect(error.code).toBe('rate_limited')
    expect(error.status).toBe(429)
  })

  it('uses a problem document when the server sends one', async () => {
    respond(
      JSON.stringify({ type: 'about:blank', title: 'Too Many Requests', status: 429 }),
      429,
      'application/problem+json',
    )

    const error = await failure()
    expect(error.code).toBe('rate_limited')
    expect(error.message).toBe('Too Many Requests')
  })

  it('rejects a 200 that is not a GraphQL result', async () => {
    respond('<!doctype html>', 200, 'text/html')

    expect((await failure()).code).toBe('invalid_response')
  })

  it('logs and reports network failures', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))

    expect((await failure()).code).toBe('network_error')
    expect(apiLog.entries[0]).toMatchObject({ label: 'GetEvent', status: 'error', bytes: 0 })
  })

  it.each([
    ['EVENT_FULL', 'event_full', 409],
    ['REGISTRATION_CLOSED', 'registration_closed', 409],
    ['NOT_FOUND', 'not_found', 404],
    ['FORBIDDEN', 'forbidden', 403],
  ])('gives error code %s the status REST would use', async (code, apiCode, status) => {
    respond(
      JSON.stringify({ data: null, errors: [{ message: 'Nope.', extensions: { code } }] }),
      200,
    )

    const error = await failure()
    expect(error.code).toBe(apiCode)
    expect(error.status).toBe(status)
    expect(error.message).toBe('Nope.')
  })

  it('maps validation errors to 422 with camelCase fields', async () => {
    respond(
      JSON.stringify({
        errors: [
          {
            message: 'Validation failed.',
            extensions: { validation: { 'input.ends_at': ['Too early.'] } },
          },
        ],
      }),
      200,
    )

    const error = await failure()
    expect(error.status).toBe(422)
    expect(error.fieldErrors).toEqual({ endsAt: ['Too early.'] })
  })

  it('reports a rejected token so the session can be cleared', async () => {
    const handler = vi.fn<() => void>()
    const unregister = onUnauthenticated(handler)
    setToken('expired')
    respond(
      JSON.stringify({
        data: null,
        errors: [{ message: 'Unauthenticated.', extensions: { guards: ['sanctum'] } }],
      }),
      200,
    )

    const error = await failure()
    expect(error.code).toBe('unauthenticated')
    expect(error.status).toBe(401)
    expect(handler).toHaveBeenCalledOnce()
    unregister()
  })

  it('does not report unauthenticated errors for guests', async () => {
    const handler = vi.fn<() => void>()
    const unregister = onUnauthenticated(handler)
    respond(
      JSON.stringify({ errors: [{ message: 'Unauthenticated.', extensions: { guards: [] } }] }),
      200,
    )

    await failure()
    expect(handler).not.toHaveBeenCalled()
    unregister()
  })
})
