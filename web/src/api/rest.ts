import createClient, { type Middleware } from 'openapi-fetch'
import type { paths } from './schema'
import { recordApiCall } from './apiLog'
import { ApiError, networkError, type ProblemDocument } from './errors'
import { API_ORIGIN } from './origin'
import { refreshTokens } from './refresh'
import { notifyUnauthenticated } from './session'
import { getToken } from './token'

export { API_ORIGIN }

const startedAt = new WeakMap<Request, number>()
/** An unread copy of each authenticated request, so it can be replayed after a token refresh. */
const replayable = new WeakMap<Request, Request>()

/** Requests whose 401 means "wrong credentials", not "expired token". */
const NO_RETRY = /\/auth\/(logout|login|refresh)$/

const auth: Middleware = {
  onRequest({ request }) {
    const token = getToken()
    if (token) request.headers.set('Authorization', `Bearer ${token}`)
    request.headers.set('Accept', 'application/json')
    if (token) replayable.set(request, request.clone())
    startedAt.set(request, performance.now())
    return request
  },
  async onResponse({ request, response }) {
    // A token we sent was rejected: it expired or was revoked elsewhere. Logging out
    // with a dead token is expected to 401 and is handled by the logout action itself.
    if (
      response.status !== 401 ||
      !request.headers.has('Authorization') ||
      NO_RETRY.test(new URL(request.url).pathname)
    ) {
      return response
    }

    const copy = replayable.get(request)
    if (copy && (await refreshTokens())) {
      copy.headers.set('Authorization', `Bearer ${getToken()}`)
      return replay(copy)
    }

    notifyUnauthenticated()
    return response
  },
}

/** Sends a request again once, with the fresh token, and logs it like any other call. */
async function replay(request: Request): Promise<Response> {
  startedAt.set(request, performance.now())
  const response = await fetch(request)
  recordApiCall({
    transport: 'REST',
    label: label(request),
    status: response.status,
    durationMs: elapsed(request),
    bytes: (await response.clone().text()).length,
  })
  if (response.status === 401) notifyUnauthenticated()
  return response
}

function label(request: Request): string {
  const url = new URL(request.url)
  return `${request.method} ${url.pathname.replace('/api/v1', '')}${url.search}`
}

function elapsed(request: Request): number {
  return Math.round(performance.now() - (startedAt.get(request) ?? performance.now()))
}

const logging: Middleware = {
  async onResponse({ request, response }) {
    recordApiCall({
      transport: 'REST',
      label: label(request),
      status: response.status,
      durationMs: elapsed(request),
      bytes:
        Number(response.headers.get('Content-Length')) || (await response.clone().text()).length,
    })
    return response
  },
  onError({ request }) {
    // The request never got a response (offline, CORS, API down); `unwrap` turns it into an ApiError.
    recordApiCall({
      transport: 'REST',
      label: label(request),
      status: 'error',
      durationMs: elapsed(request),
      bytes: 0,
    })
  },
}

/**
 * Typed REST client. Paths, parameters, bodies and responses are all checked at
 * compile time against types generated from ../openapi.yaml (`npm run gen:api`).
 * An expired access token is refreshed and the request retried once, transparently.
 */
export const rest = createClient<paths>({ baseUrl: `${API_ORIGIN}/api/v1` })
rest.use(auth, logging)

/**
 * Unwraps an openapi-fetch result: returns the body on success and throws an
 * ApiError built from the problem document otherwise.
 */
export async function unwrap<T>(
  call: Promise<{ data?: T; error?: unknown; response: Response }>,
): Promise<T> {
  let result: Awaited<typeof call>
  try {
    result = await call
  } catch {
    throw networkError(API_ORIGIN)
  }

  const { data, error, response } = result

  if (error !== undefined || !response.ok) {
    const problem = error as Partial<ProblemDocument> | undefined
    throw ApiError.fromProblem({
      type: problem?.type ?? 'about:blank',
      title: problem?.title ?? (response.statusText || `HTTP ${response.status}`),
      status: problem?.status ?? response.status,
      detail: problem?.detail,
      errors: problem?.errors,
    })
  }

  return data as T
}
