import createClient, { type Middleware } from 'openapi-fetch'
import type { paths } from './schema'
import { recordApiCall } from './apiLog'
import { ApiError, type ProblemDocument } from './errors'
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
}

const logging: Middleware = {
  async onResponse({ request, response }) {
    const url = new URL(request.url)
    recordApiCall({
      transport: 'REST',
      label: `${request.method} ${url.pathname.replace('/api/v1', '')}${url.search}`,
      status: response.status,
      durationMs: Math.round(performance.now() - (startedAt.get(request) ?? performance.now())),
      bytes:
        Number(response.headers.get('Content-Length')) || (await response.clone().text()).length,
    })
    return response
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
    throw new ApiError('network_error', `Couldn't reach the API at ${API_ORIGIN}. Is it running?`)
  }

  const { data, error, response } = result

  if (error !== undefined || !response.ok) {
    const problem = error as Partial<ProblemDocument> | undefined
    throw ApiError.fromProblem({
      type: problem?.type ?? 'about:blank',
      title: problem?.title ?? response.statusText,
      status: problem?.status ?? response.status,
      detail: problem?.detail,
      errors: problem?.errors,
    })
  }

  return data as T
}
