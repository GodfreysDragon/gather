import { describe, expect, it } from 'vitest'
import { toApiError } from '@/api/graphql'
import { ApiError, problemCode } from '@/api/errors'

describe('ApiError', () => {
  it('derives a code from a problem type URI', () => {
    expect(problemCode({ type: 'http://localhost:8000/problems/event-full', status: 409 })).toBe(
      'event_full',
    )
  })

  it('derives a code from the status for about:blank problems', () => {
    expect(problemCode({ type: 'about:blank', status: 404 })).toBe('not_found')
    expect(problemCode({ type: 'about:blank', status: 418 })).toBe('http_error')
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
    const validation = toApiError({
      message: 'Validation failed for the field [createEvent].',
      extensions: { validation: { 'input.endsAt': ['Must be after start.'] } },
    })
    expect(validation.isValidation).toBe(true)
    expect(validation.fieldErrors).toEqual({ endsAt: ['Must be after start.'] })

    const full = toApiError({
      message: '"Tiny" has no spots left.',
      extensions: { code: 'EVENT_FULL' },
    })
    expect(full.code).toBe('event_full')
    expect(full.message).toBe('"Tiny" has no spots left.')
  })
})
