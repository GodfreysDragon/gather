/**
 * The UI's own model. Views and components only ever see these types; each
 * transport adapter maps its wire format (REST snake_case JSON, GraphQL camelCase
 * selections) onto them.
 */
export type EventStatus = 'draft' | 'published' | 'cancelled'

export interface Venue {
  id: number
  name: string
  address: string
  city: string
  capacity: number
}

export interface Person {
  id: number
  name: string
}

export interface EventItem {
  id: number
  title: string
  description: string
  startsAt: Date
  endsAt: Date
  status: EventStatus
  capacity: number
  registrationCount: number
  spotsLeft: number
  viewer: { isRegistered: boolean; isOrganizer: boolean }
  venue: Venue
  organizer: Person
}

export interface Attendee {
  id: number
  name: string
  registeredAt: Date
}

export type EventSort =
  'starts_at' | '-starts_at' | 'title' | '-title' | 'created_at' | '-created_at'

export interface EventFilters {
  q?: string
  city?: string
  from?: Date
  hasSpots?: boolean
  organizerId?: number
  sort?: EventSort
}

export interface Page<T> {
  items: T[]
  page: number
  lastPage: number
  total: number
}

export interface EventInput {
  venueId: number
  title: string
  description: string
  startsAt: Date
  endsAt: Date
  capacity: number
  status: EventStatus
}
