import type { Attendee, EventFilters, EventInput, EventItem, MyEvents, Page, Venue } from './types'

/**
 * Everything the UI needs from the backend, independent of transport. There are
 * two implementations, REST and GraphQL, and the header toggle swaps between them
 * at runtime. No view changes when it does, which is the point.
 */
export interface EventsRepository {
  listEvents(filters: EventFilters, page: number, perPage?: number): Promise<Page<EventItem>>
  /** Resolves to null if the event doesn't exist or isn't visible to the viewer. */
  getEvent(id: number): Promise<EventItem | null>
  /**
   * `idempotencyKey` makes a retry safe: the REST API replays the first result instead
   * of creating a second event. The GraphQL API has no equivalent and ignores it.
   */
  createEvent(input: EventInput, options?: { idempotencyKey?: string }): Promise<EventItem>
  updateEvent(id: number, input: Partial<EventInput>): Promise<EventItem>
  deleteEvent(id: number): Promise<void>

  /** Idempotent. Returns the updated event. */
  register(eventId: number): Promise<EventItem>
  /** Idempotent. Returns the updated event. */
  cancelRegistration(eventId: number): Promise<EventItem>
  listAttendees(eventId: number): Promise<Attendee[]>

  /**
   * The viewer's dashboard: upcoming events they're going to, and the events they
   * organize (drafts included). Two REST calls, one GraphQL query.
   */
  myEvents(viewerId: number): Promise<MyEvents>

  listVenues(): Promise<Venue[]>
}

export type Transport = 'rest' | 'graphql'
