/**
 * One error type for the whole UI, whichever transport produced it. REST problem
 * documents (RFC 9457) and GraphQL errors are both normalised into an ApiError, so
 * views never need to know which API they're talking to.
 */
export type FieldErrors = Record<string, string[]>

export interface ProblemDocument {
  type: string
  title: string
  status: number
  detail?: string
  errors?: FieldErrors
}

export class ApiError extends Error {
  constructor(
    /** Stable machine-readable code, e.g. "event_full", "validation_failed", "not_found". */
    readonly code: string,
    message: string,
    readonly status: number | null = null,
    /** Validation messages keyed by camelCase field name. */
    readonly fieldErrors: FieldErrors = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  get isValidation(): boolean {
    return this.code === 'validation_failed'
  }

  static fromProblem(problem: ProblemDocument): ApiError {
    return new ApiError(
      problemCode(problem),
      problem.detail ?? problem.title,
      problem.status,
      camelizeKeys(problem.errors ?? {}),
    )
  }
}

/** `http://host/problems/event-full` → `event_full`; `about:blank` → derived from the HTTP status. */
export function problemCode(problem: Pick<ProblemDocument, 'type' | 'status'>): string {
  const match = problem.type.match(/\/problems\/([a-z0-9-]+)$/)
  if (match?.[1]) return match[1].replace(/-/g, '_')

  return (
    {
      401: 'unauthenticated',
      403: 'forbidden',
      404: 'not_found',
      429: 'rate_limited',
    }[problem.status] ?? (problem.status >= 500 ? 'server_error' : 'http_error')
  )
}

/** Normalises anything a `catch` block can receive into an ApiError. */
export function toApiError(error: unknown): ApiError {
  if (error instanceof ApiError) return error
  return new ApiError('unknown', error instanceof Error ? error.message : String(error))
}

/** Thrown when fetch itself fails: DNS, CORS, connection refused, offline. */
export function networkError(origin: string): ApiError {
  return new ApiError('network_error', `Couldn't reach the API at ${origin}. Is it running?`)
}

export function camelizeKeys(errors: FieldErrors): FieldErrors {
  return Object.fromEntries(
    Object.entries(errors).map(([key, messages]) => [
      key.replace(/^input\./, '').replace(/_([a-z])/g, (_, c: string) => c.toUpperCase()),
      messages,
    ]),
  )
}
