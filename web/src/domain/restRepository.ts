import { ApiError } from '@/api/errors'
import { rest, unwrap } from '@/api/rest'
import type { components } from '@/api/schema'
import type { EventsRepository } from './repository'
import type { Attendee, EventInput, EventItem } from './types'

type RestEvent = components['schemas']['Event']
type RestEventPatch = components['schemas']['EventPatch']

export function fromRestEvent(event: RestEvent): EventItem {
  return {
    id: event.id,
    title: event.title,
    description: event.description,
    startsAt: new Date(event.starts_at),
    endsAt: new Date(event.ends_at),
    status: event.status,
    capacity: event.capacity,
    registrationCount: event.registration_count,
    spotsLeft: event.spots_left,
    viewer: { isRegistered: event.viewer.is_registered, isOrganizer: event.viewer.is_organizer },
    venue: event.venue,
    organizer: { id: event.organizer.id, name: event.organizer.name },
  }
}

function toRestPatch(input: Partial<EventInput>): RestEventPatch {
  return {
    venue_id: input.venueId,
    title: input.title,
    description: input.description,
    starts_at: input.startsAt?.toISOString(),
    ends_at: input.endsAt?.toISOString(),
    capacity: input.capacity,
    status: input.status,
  }
}

async function getExisting(repository: EventsRepository, id: number): Promise<EventItem> {
  const event = await repository.getEvent(id)
  if (!event) throw new ApiError('not_found', 'This event no longer exists.', 404)
  return event
}

/**
 * REST adapter. Every call is type-checked against the OpenAPI contract: rename a
 * field in openapi.yaml, regenerate, and this file stops compiling.
 */
/** Every page of an organizer's events, so REST shows the same list as GraphQL. */
async function organizedBy(
  repository: EventsRepository,
  organizerId: number,
): Promise<EventItem[]> {
  const first = await repository.listEvents({ organizerId }, 1, 50)
  const others = await Promise.all(
    Array.from({ length: first.lastPage - 1 }, (_, i) =>
      repository.listEvents({ organizerId }, i + 2, 50),
    ),
  )
  return [first, ...others].flatMap((page) => page.items)
}

export const restRepository: EventsRepository = {
  async listEvents(filters, page, perPage = 12) {
    const body = await unwrap(
      rest.GET('/events', {
        params: {
          query: {
            q: filters.q || undefined,
            city: filters.city || undefined,
            from: filters.from?.toISOString(),
            has_spots: filters.hasSpots ? 'true' : undefined,
            organizer_id: filters.organizerId,
            sort: filters.sort,
            page,
            per_page: perPage,
          },
        },
      }),
    )

    return {
      items: body.data.map(fromRestEvent),
      page: body.meta.current_page,
      lastPage: body.meta.last_page,
      total: body.meta.total,
    }
  },

  async getEvent(id) {
    try {
      const body = await unwrap(rest.GET('/events/{event}', { params: { path: { event: id } } }))
      return fromRestEvent(body.data)
    } catch (error) {
      if (error instanceof ApiError && error.code === 'not_found') return null
      throw error
    }
  },

  async createEvent(input) {
    const body = await unwrap(
      rest.POST('/events', {
        body: {
          venue_id: input.venueId,
          title: input.title,
          description: input.description,
          starts_at: input.startsAt.toISOString(),
          ends_at: input.endsAt.toISOString(),
          capacity: input.capacity,
          status: input.status === 'cancelled' ? undefined : input.status,
        },
      }),
    )
    return fromRestEvent(body.data)
  },

  async updateEvent(id, input) {
    const body = await unwrap(
      rest.PATCH('/events/{event}', { params: { path: { event: id } }, body: toRestPatch(input) }),
    )
    return fromRestEvent(body.data)
  },

  async deleteEvent(id) {
    await unwrap(rest.DELETE('/events/{event}', { params: { path: { event: id } } }))
  },

  async register(eventId) {
    const body = await unwrap(
      rest.PUT('/events/{event}/registration', { params: { path: { event: eventId } } }),
    )
    // The contract marks the embedded event optional; fall back to fetching it.
    return body.data.event ? fromRestEvent(body.data.event) : getExisting(this, eventId)
  },

  async cancelRegistration(eventId) {
    await unwrap(
      rest.DELETE('/events/{event}/registration', { params: { path: { event: eventId } } }),
    )
    // DELETE returns 204, so fetch the updated counts.
    return getExisting(this, eventId)
  },

  async listAttendees(eventId) {
    const body = await unwrap(
      rest.GET('/events/{event}/attendees', {
        params: { path: { event: eventId }, query: { per_page: 100 } },
      }),
    )
    return body.data.flatMap((registration): Attendee[] =>
      registration.user
        ? [
            {
              id: registration.user.id,
              name: registration.user.name,
              registeredAt: new Date(registration.registered_at),
            },
          ]
        : [],
    )
  },

  async myEvents(viewerId) {
    const [registrations, organizing] = await Promise.all([
      unwrap(rest.GET('/me/registrations')),
      organizedBy(this, viewerId),
    ])
    return { going: registrations.data.map(fromRestEvent), organizing }
  },

  async listVenues() {
    const body = await unwrap(rest.GET('/venues'))
    return body.data
  },
}
