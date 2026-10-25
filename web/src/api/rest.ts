import createClient, { type Middleware } from 'openapi-fetch'
import type { paths } from './schema'
import { recordApiCall } from './apiLog'
import { ApiError, networkError, type ProblemDocument } from './errors'
import { notifyUnauthenticated } from './session'
import { getToken } from './token'

export const API_ORIGIN = import.meta.env.VITE_API_URL.replace(/\/$/, '')

const startedAt = new WeakMap<Request, number>()

const auth: Middleware = {
  onRequest({ request }) {
    const token = getToken()
    if (token) request.headers.set('Authorization', `Bearer ${token}`)
    request.headers.set('Accept', 'application/json')
    startedAt.set(request, performance.now())
    return request
  },
  onResponse({ request, response }) {
    // A token we sent was rejected: it expired or was revoked elsewhere. Logging out
    // with a dead token is expected to 401 and is handled by the logout action itself.
    if (
      response.status === 401 &&
      request.headers.has('Authorization') &&
      !new URL(request.url).pathname.endsWith('/auth/logout')
    ) {
      notifyUnauthenticated()
    }
    return response
  },
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
