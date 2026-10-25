import { recordApiCall } from './apiLog'
import {
  ApiError,
  camelizeKeys,
  networkError,
  problemCode,
  type FieldErrors,
  type ProblemDocument,
} from './errors'
import { API_ORIGIN } from './rest'
import { notifyUnauthenticated } from './session'
import { getToken } from './token'

interface GraphQLError {
  message: string
  extensions?: {
    code?: string
    validation?: FieldErrors
    /** Lighthouse adds the failed guards to authentication errors. */
    guards?: string[]
    /** Lighthouse < 6 tagged errors with a category instead. */
    category?: string
  }
}

interface GraphQLResponse<T> {
  data?: T | null
  errors?: GraphQLError[]
}

/**
 * HTTP statuses the REST API uses for the same failures, keyed by GraphQL
 * `extensions.code`. Any other code is a business-rule violation, which REST
 * reports as 409, so ErrorAlert shows the same thing for both transports.
 */
const STATUS_BY_CODE: Record<string, number> = {
  unauthenticated: 401,
  forbidden: 403,
  not_found: 404,
  validation_failed: 422,
  rate_limited: 429,
}

/**
 * Minimal GraphQL client: one POST per operation, same bearer token as REST.
 * Deliberately dependency-free; the point of this app is the API, not a client cache.
 */
export async function graphql<T>(
  query: string,
  variables: Record<string, unknown> = {},
): Promise<T> {
  const operation = query.match(/(query|mutation)\s+(\w+)/)?.[2] ?? 'anonymous'
  const started = performance.now()
  const token = getToken()
  const log = (status: number | 'error', bytes: number) =>
    recordApiCall({
      transport: 'GraphQL',
      label: operation,
      status,
      durationMs: Math.round(performance.now() - started),
      bytes,
    })

  let response: Response
  let text: string
  try {
    response = await fetch(`${API_ORIGIN}/graphql`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({ query, variables }),
    })
    text = await response.text()
  } catch {
    log('error', 0)
    throw networkError(API_ORIGIN)
  }

  const body = parseJson(response, text)
  const [error] = (body as GraphQLResponse<T> | null)?.errors ?? []
  log(!response.ok ? response.status : error ? 'error' : response.status, text.length)

  if (error) {
    const apiError = fromGraphQLError(error)
    if (apiError.status === 401 && token) notifyUnauthenticated()
    throw apiError
  }

  if (!response.ok) {
    if (response.status === 401 && token) notifyUnauthenticated()
    throw httpError(response, body)
  }

  if (body === null || typeof body !== 'object' || !('data' in body)) {
    throw new ApiError(
      'invalid_response',
      'The API sent a response that is not a GraphQL result.',
      response.status,
    )
  }

  return (body as GraphQLResponse<T>).data as T
}

/** The parsed body if the server sent JSON, otherwise null (e.g. an HTML error page from a proxy). */
function parseJson(response: Response, text: string): unknown {
  if (!/\bjson\b/i.test(response.headers.get('Content-Type') ?? '')) return null
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}

/** A non-2xx response without GraphQL errors: rate limiting, a crash, a gateway timeout… */
function httpError(response: Response, body: unknown): ApiError {
  const problem = body as Partial<ProblemDocument> | null
  if (problem && typeof problem.type === 'string' && typeof problem.title === 'string') {
    return ApiError.fromProblem({ ...problem, status: response.status } as ProblemDocument)
  }

  const code = problemCode({ type: 'about:blank', status: response.status })
  const message =
    code === 'rate_limited'
      ? 'Too many requests. Please wait a moment and try again.'
      : code === 'server_error'
        ? `The API failed to handle this request (HTTP ${response.status}).`
        : `The API responded with HTTP ${response.status}.`
  return new ApiError(code, message, response.status)
}

/** Maps a GraphQL error onto the ApiError the REST API would have produced for the same failure. */
export function fromGraphQLError(error: GraphQLError): ApiError {
  const ext = error.extensions ?? {}

  if (ext.validation) {
    return new ApiError('validation_failed', 'Validation failed', 422, camelizeKeys(ext.validation))
  }
  if (ext.guards !== undefined || ext.category === 'authentication') {
    return new ApiError('unauthenticated', error.message, 401)
  }
  if (ext.code) {
    const code = ext.code.toLowerCase()
    return new ApiError(code, error.message, STATUS_BY_CODE[code] ?? 409)
  }

  return new ApiError('graphql_error', error.message)
}
