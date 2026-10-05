import type { Attendee, EventFilters, EventInput, EventItem, Page, Venue } from './types'

/**
 * Everything the UI needs from the backend, independent of transport. There are
 * two implementations, REST and GraphQL, and the header toggle swaps between them
 * at runtime. No view changes when it does, which is the point.
 */
export interface EventsRepository {
  listEvents(filters: EventFilters, page: number, perPage?: number): Promise<Page<EventItem>>
  /** Resolves to null if the event doesn't exist or isn't visible to the viewer. */
  getEvent(id: number): Promise<EventItem | null>
  createEvent(input: EventInput): Promise<EventItem>
  updateEvent(id: number, input: Partial<EventInput>): Promise<EventItem>
  deleteEvent(id: number): Promise<void>

  /** Idempotent. Returns the updated event. */
  register(eventId: number): Promise<EventItem>
  /** Idempotent. Returns the updated event. */
  cancelRegistration(eventId: number): Promise<EventItem>
  listAttendees(eventId: number): Promise<Attendee[]>

  /** Upcoming events the viewer is registered for. */
  myRegistrations(): Promise<EventItem[]>
  /** Events the viewer organizes, drafts included. */
  myOrganizedEvents(viewerId: number): Promise<EventItem[]>

  listVenues(): Promise<Venue[]>
}

export type Transport = 'rest' | 'graphql'
