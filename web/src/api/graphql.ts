import { recordApiCall } from './apiLog'
import { ApiError, camelizeKeys, type FieldErrors } from './errors'
import { API_ORIGIN } from './rest'
import { getToken } from './token'

interface GraphQLError {
  message: string
  extensions?: { code?: string; validation?: FieldErrors; category?: string }
}

interface GraphQLResponse<T> {
  data?: T | null
  errors?: GraphQLError[]
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

  let response: Response
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
  } catch {
    throw new ApiError('network_error', `Couldn't reach the API at ${API_ORIGIN}. Is it running?`)
  }

  const text = await response.text()
  const body = JSON.parse(text) as GraphQLResponse<T>

  recordApiCall({
    transport: 'GraphQL',
    label: operation,
    status: body.errors?.length ? 'error' : response.status,
    durationMs: Math.round(performance.now() - started),
    bytes: text.length,
  })

  const [error] = body.errors ?? []
  if (error) throw toApiError(error)

  return body.data as T
}

export function toApiError(error: GraphQLError): ApiError {
  const ext = error.extensions ?? {}

  if (ext.validation) {
    return new ApiError('validation_failed', 'Validation failed', 422, camelizeKeys(ext.validation))
  }
  if (error.message === 'Unauthenticated.') {
    return new ApiError('unauthenticated', error.message, 401)
  }
  if (error.message === 'This action is unauthorized.') {
    return new ApiError('forbidden', error.message, 403)
  }

  return new ApiError(ext.code?.toLowerCase() ?? 'graphql_error', error.message)
}
