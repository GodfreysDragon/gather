import { describe, expect, it } from 'vitest'
import { fromGraphQLError } from '@/api/graphql'
import { ApiError, problemCode, toApiError } from '@/api/errors'

describe('ApiError', () => {
  it('derives a code from a problem type URI', () => {
    expect(problemCode({ type: 'http://localhost:8000/problems/event-full', status: 409 })).toBe(
      'event_full',
    )
  })

  it('derives a code from the status for about:blank problems', () => {
    expect(problemCode({ type: 'about:blank', status: 404 })).toBe('not_found')
    expect(problemCode({ type: 'about:blank', status: 418 })).toBe('http_error')
    expect(problemCode({ type: 'about:blank', status: 503 })).toBe('server_error')
  })

  it('normalises anything caught into an ApiError', () => {
    const original = new ApiError('event_full', 'Full', 409)
    expect(toApiError(original)).toBe(original)
    expect(toApiError(new Error('Boom'))).toMatchObject({ code: 'unknown', message: 'Boom' })
    expect(toApiError('weird')).toMatchObject({ code: 'unknown', message: 'weird' })
  })

  it('normalises REST validation errors to camelCase field names', () => {
    const error = ApiError.fromProblem({
      type: 'http://localhost:8000/problems/validation-failed',
      title: 'Validation failed',
      status: 422,
      detail: 'The ends at field must be a date after starts at.',
      errors: {
        ends_at: ['The ends at field must be a date after starts at.'],
        venue_id: ['Required.'],
      },
    })

    expect(error.isValidation).toBe(true)
    expect(Object.keys(error.fieldErrors)).toEqual(['endsAt', 'venueId'])
  })

  it('normalises GraphQL validation and business errors the same way', () => {
    const validation = fromGraphQLError({
      message: 'Validation failed for the field [createEvent].',
      extensions: { validation: { 'input.endsAt': ['Must be after start.'] } },
    })
    expect(validation.isValidation).toBe(true)
    expect(validation.fieldErrors).toEqual({ endsAt: ['Must be after start.'] })

    const full = fromGraphQLError({
      message: '"Tiny" has no spots left.',
      extensions: { code: 'EVENT_FULL' },
    })
    expect(full.code).toBe('event_full')
    expect(full.status).toBe(409)
    expect(full.message).toBe('"Tiny" has no spots left.')
  })
})
