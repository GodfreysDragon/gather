import { graphql } from '@/api/graphql'
import type { EventsRepository } from './repository'
import type { EventInput, EventItem, EventSort, EventStatus, Venue } from './types'

/** The shape selected by EVENT_FIELDS below. */
export interface GraphQLEvent {
  id: string
  title: string
  description: string
  startsAt: string
  endsAt: string
  status: 'DRAFT' | 'PUBLISHED' | 'CANCELLED'
  capacity: number
  registrationCount: number
  spotsLeft: number
  viewerIsRegistered: boolean
  viewerIsOrganizer: boolean
  venue: { id: string; name: string; address: string; city: string; capacity: number }
  organizer: { id: string; name: string }
}

const EVENT_FIELDS = /* GraphQL */ `
  fragment EventFields on Event {
    id
    title
    description
    startsAt
    endsAt
    status
    capacity
    registrationCount
    spotsLeft
    viewerIsRegistered
    viewerIsOrganizer
    venue {
      id
      name
      address
      city
      capacity
    }
    organizer {
      id
      name
    }
  }
`

const SORTS: Record<EventSort, string> = {
  starts_at: 'STARTS_AT_ASC',
  '-starts_at': 'STARTS_AT_DESC',
  title: 'TITLE_ASC',
  '-title': 'TITLE_DESC',
  created_at: 'CREATED_AT_ASC',
  '-created_at': 'CREATED_AT_DESC',
}

export function fromGraphQLEvent(event: GraphQLEvent): EventItem {
  return {
    id: Number(event.id),
    title: event.title,
    description: event.description,
    startsAt: new Date(event.startsAt),
    endsAt: new Date(event.endsAt),
    status: event.status.toLowerCase() as EventStatus,
    capacity: event.capacity,
    registrationCount: event.registrationCount,
    spotsLeft: event.spotsLeft,
    viewer: { isRegistered: event.viewerIsRegistered, isOrganizer: event.viewerIsOrganizer },
    venue: { ...event.venue, id: Number(event.venue.id) },
    organizer: { id: Number(event.organizer.id), name: event.organizer.name },
  }
}

function toGraphQLInput(input: Partial<EventInput>): Record<string, unknown> {
  const out: Record<string, unknown> = {
    venueId: input.venueId,
    title: input.title,
    description: input.description,
    startsAt: input.startsAt?.toISOString(),
    endsAt: input.endsAt?.toISOString(),
    capacity: input.capacity,
    status: input.status?.toUpperCase(),
  }
  return Object.fromEntries(Object.entries(out).filter(([, value]) => value !== undefined))
}

/**
 * GraphQL adapter. Each screen's data comes back in a single round trip with
 * exactly the fields the UI renders, e.g. the "My events" page is one `me` query
 * instead of two REST calls.
 */
export const graphqlRepository: EventsRepository = {
  async listEvents(filters, page, perPage = 12) {
    const data = await graphql<{
      events: {
        data: GraphQLEvent[]
        paginatorInfo: { currentPage: number; lastPage: number; total: number }
      }
    }>(
      `
        query ListEvents(
          $q: String
          $city: String
          $from: DateTime
          $hasSpots: Boolean
          $organizerId: ID
          $sort: EventSort
          $page: Int
          $first: Int!
        ) {
          events(
            q: $q
            city: $city
            from: $from
            hasSpots: $hasSpots
            organizerId: $organizerId
            sort: $sort
            page: $page
            first: $first
          ) {
            data {
              ...EventFields
            }
            paginatorInfo {
              currentPage
              lastPage
              total
            }
          }
        }
        ${EVENT_FIELDS}
      `,
      {
        q: filters.q || null,
        city: filters.city || null,
        from: filters.from?.toISOString() ?? null,
        hasSpots: filters.hasSpots || null,
        organizerId: filters.organizerId ?? null,
        sort: SORTS[filters.sort ?? 'starts_at'],
        page,
        first: perPage,
      },
    )

    return {
      items: data.events.data.map(fromGraphQLEvent),
      page: data.events.paginatorInfo.currentPage,
      lastPage: data.events.paginatorInfo.lastPage,
      total: data.events.paginatorInfo.total,
    }
  },

  async getEvent(id) {
    const data = await graphql<{ event: GraphQLEvent | null }>(
      `
        query GetEvent($id: ID!) {
          event(id: $id) {
            ...EventFields
          }
        }
        ${EVENT_FIELDS}
      `,
      { id },
    )
    return data.event ? fromGraphQLEvent(data.event) : null
  },

  async createEvent(input) {
    const data = await graphql<{ createEvent: GraphQLEvent }>(
      `
        mutation CreateEvent($input: CreateEventInput!) {
          createEvent(input: $input) {
            ...EventFields
          }
        }
        ${EVENT_FIELDS}
      `,
      { input: toGraphQLInput(input) },
    )
    return fromGraphQLEvent(data.createEvent)
  },

  async updateEvent(id, input) {
    const data = await graphql<{ updateEvent: GraphQLEvent }>(
      `
        mutation UpdateEvent($id: ID!, $input: UpdateEventInput!) {
          updateEvent(id: $id, input: $input) {
            ...EventFields
          }
        }
        ${EVENT_FIELDS}
      `,
      { id, input: toGraphQLInput(input) },
    )
    return fromGraphQLEvent(data.updateEvent)
  },

  async deleteEvent(id) {
    await graphql(
      `
        mutation DeleteEvent($id: ID!) {
          deleteEvent(id: $id)
        }
      `,
      { id },
    )
  },

  async register(eventId) {
    const data = await graphql<{ registerForEvent: { event: GraphQLEvent } }>(
      `
        mutation RegisterForEvent($id: ID!) {
          registerForEvent(eventId: $id) {
            created
            event {
              ...EventFields
            }
          }
        }
        ${EVENT_FIELDS}
      `,
      { id: eventId },
    )
    return fromGraphQLEvent(data.registerForEvent.event)
  },

  async cancelRegistration(eventId) {
    const data = await graphql<{ cancelRegistration: GraphQLEvent }>(
      `
        mutation CancelRegistration($id: ID!) {
          cancelRegistration(eventId: $id) {
            ...EventFields
          }
        }
        ${EVENT_FIELDS}
      `,
      { id: eventId },
    )
    return fromGraphQLEvent(data.cancelRegistration)
  },

  async listAttendees(eventId) {
    const data = await graphql<{
      event: {
        attendees: { data: { user: { id: string; name: string }; registeredAt: string }[] }
      } | null
    }>(
      `
        query ListAttendees($id: ID!) {
          event(id: $id) {
            attendees(first: 100) {
              data {
                user {
                  id
                  name
                }
                registeredAt
              }
            }
          }
        }
      `,
      { id: eventId },
    )
    return (data.event?.attendees.data ?? []).map((registration) => ({
      id: Number(registration.user.id),
      name: registration.user.name,
      registeredAt: new Date(registration.registeredAt),
    }))
  },

  async myEvents() {
    const data = await graphql<{
      me: { registeredEvents: GraphQLEvent[]; organizedEvents: GraphQLEvent[] } | null
    }>(`
      query MyEvents {
        me {
          registeredEvents {
            ...EventFields
          }
          organizedEvents {
            ...EventFields
          }
        }
      }
      ${EVENT_FIELDS}
    `)
    return {
      going: (data.me?.registeredEvents ?? []).map(fromGraphQLEvent),
      organizing: (data.me?.organizedEvents ?? []).map(fromGraphQLEvent),
    }
  },

  async listVenues() {
    const data = await graphql<{ venues: (Omit<Venue, 'id'> & { id: string })[] }>(`
      query ListVenues {
        venues {
          id
          name
          address
          city
          capacity
        }
      }
    `)
    return data.venues.map((venue) => ({ ...venue, id: Number(venue.id) }))
  },
}
