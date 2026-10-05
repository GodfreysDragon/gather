import { describe, expect, it } from 'vitest'
import type { components } from '@/api/schema'
import { fromGraphQLEvent, type GraphQLEvent } from '@/domain/graphqlRepository'
import { fromRestEvent } from '@/domain/restRepository'

const restEvent: components['schemas']['Event'] = {
  id: 7,
  title: 'GraphQL night',
  description: 'Schemas and resolvers.',
  starts_at: '2026-11-01T18:00:00Z',
  ends_at: '2026-11-01T21:00:00Z',
  status: 'published',
  capacity: 40,
  registration_count: 12,
  spots_left: 28,
  viewer: { is_registered: true, is_organizer: false },
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
  links: {
    self: 'http://localhost:8000/api/v1/events/7',
    registration: 'http://localhost:8000/api/v1/events/7/registration',
  },
}

const graphqlEvent: GraphQLEvent = {
  id: '7',
  title: 'GraphQL night',
  description: 'Schemas and resolvers.',
  startsAt: '2026-11-01T18:00:00Z',
  endsAt: '2026-11-01T21:00:00Z',
  status: 'PUBLISHED',
  capacity: 40,
  registrationCount: 12,
  spotsLeft: 28,
  viewerIsRegistered: true,
  viewerIsOrganizer: false,
  venue: {
    id: '3',
    name: 'Factory Hall',
    address: 'Lohmühlenstraße 65',
    city: 'Berlin',
    capacity: 300,
  },
  organizer: { id: '9', name: 'Ada' },
}

describe('transport adapters', () => {
  it('map REST and GraphQL payloads for the same event to the same UI model', () => {
    expect(fromGraphQLEvent(graphqlEvent)).toEqual(fromRestEvent(restEvent))
  })

  it('parse instants into Date objects', () => {
    const event = fromRestEvent(restEvent)
    expect(event.startsAt).toBeInstanceOf(Date)
    expect(event.startsAt.toISOString()).toBe('2026-11-01T18:00:00.000Z')
  })
})
