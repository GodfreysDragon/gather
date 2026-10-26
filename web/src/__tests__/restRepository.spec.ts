import { beforeEach, describe, expect, it, vi } from 'vitest'
import { restRepository } from '@/domain/restRepository'

// openapi-fetch captures `fetch` when the client is created, so stub it before any import runs.
const fetchMock = vi.hoisted(() => {
  const mock = vi.fn<typeof fetch>()
  globalThis.fetch = mock
  return mock
})

const created = {
  data: {
    id: 7,
    title: 'Retry-safe workshop',
    description: 'Created exactly once.',
    starts_at: '2026-11-01T18:00:00Z',
    ends_at: '2026-11-01T20:00:00Z',
    status: 'published',
    capacity: 30,
    registration_count: 0,
    spots_left: 30,
    viewer: { is_registered: false, is_organizer: true },
    venue: {
      id: 3,
      name: 'Factory Hall',
      address: 'Lohmühlenstraße 65',
      city: 'Berlin',
      capacity: 300,
    },
    organizer: { id: 9, name: 'Ada' },
    created_at: '2026-10-01T09:00:00Z',
    updated_at: '2026-10-01T09:00:00Z',
    deleted_at: null,
    links: {
      self: 'http://api.test/api/v1/events/7',
      registration: 'http://api.test/api/v1/events/7/registration',
    },
  },
}

const input = {
  venueId: 3,
  title: 'Retry-safe workshop',
  description: 'Created exactly once.',
  startsAt: new Date('2026-11-01T18:00:00Z'),
  endsAt: new Date('2026-11-01T20:00:00Z'),
  capacity: 30,
  status: 'published' as const,
}

describe('restRepository.createEvent', () => {
  beforeEach(() => {
    fetchMock.mockReset()
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify(created), {
        status: 201,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
  })

  it('sends the idempotency key so a retry cannot create a second event', async () => {
    const event = await restRepository.createEvent(input, { idempotencyKey: 'attempt-1' })

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(request.headers.get('Idempotency-Key')).toBe('attempt-1')
    expect(event.id).toBe(7)
  })

  it('sends no key when none is given', async () => {
    await restRepository.createEvent(input)

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.headers.has('Idempotency-Key')).toBe(false)
  })
})
