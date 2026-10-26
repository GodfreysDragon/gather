/** Internal type. DO NOT USE DIRECTLY. */
type Exact<T extends { [key: string]: unknown }> = { [K in keyof T]: T[K] }
/** Internal type. DO NOT USE DIRECTLY. */
export type Incremental<T> =
  T | { [P in keyof T]?: P extends ' $fragmentName' | '__typename' ? T[P] : never }
export type CreateEventInput = {
  capacity: number
  description: string
  endsAt: string
  startsAt: string
  /** DRAFT or PUBLISHED (default). */
  status?: EventStatus | null | undefined
  title: string
  venueId: string | number
}

export type EventSort =
  | 'CREATED_AT_ASC'
  | 'CREATED_AT_DESC'
  | 'STARTS_AT_ASC'
  | 'STARTS_AT_DESC'
  | 'TITLE_ASC'
  | 'TITLE_DESC'

export type EventStatus = 'CANCELLED' | 'DRAFT' | 'PUBLISHED'

export type UpdateEventInput = {
  capacity?: number | null | undefined
  description?: string | null | undefined
  endsAt?: string | null | undefined
  startsAt?: string | null | undefined
  status?: EventStatus | null | undefined
  title?: string | null | undefined
  venueId?: string | number | null | undefined
}

export type EventFieldsFragment = {
  id: string
  title: string
  description: string
  startsAt: string
  endsAt: string
  status: EventStatus
  capacity: number
  registrationCount: number
  spotsLeft: number
  viewerIsRegistered: boolean
  viewerIsOrganizer: boolean
  venue: { id: string; name: string; address: string; city: string; capacity: number }
  organizer: { id: string; name: string }
}

export type ListEventsQueryVariables = Exact<{
  q?: string | null | undefined
  city?: string | null | undefined
  from?: string | null | undefined
  hasSpots?: boolean | null | undefined
  organizerId?: string | number | null | undefined
  sort?: EventSort | null | undefined
  page?: number | null | undefined
  first: number
}>

export type ListEventsQuery = {
  events: {
    data: Array<EventFieldsFragment>
    paginatorInfo: { currentPage: number; lastPage: number; total: number }
  }
}

export type GetEventQueryVariables = Exact<{
  id: string | number
}>

export type GetEventQuery = { event: EventFieldsFragment | null }

export type CreateEventMutationVariables = Exact<{
  input: CreateEventInput
}>

export type CreateEventMutation = { createEvent: EventFieldsFragment }

export type UpdateEventMutationVariables = Exact<{
  id: string | number
  input: UpdateEventInput
}>

export type UpdateEventMutation = { updateEvent: EventFieldsFragment }

export type DeleteEventMutationVariables = Exact<{
  id: string | number
}>

export type DeleteEventMutation = { deleteEvent: string }

export type RegisterForEventMutationVariables = Exact<{
  id: string | number
}>

export type RegisterForEventMutation = {
  registerForEvent: { created: boolean; event: EventFieldsFragment }
}

export type CancelRegistrationMutationVariables = Exact<{
  id: string | number
}>

export type CancelRegistrationMutation = { cancelRegistration: EventFieldsFragment }

export type ListAttendeesQueryVariables = Exact<{
  id: string | number
}>

export type ListAttendeesQuery = {
  event: {
    attendees: { data: Array<{ registeredAt: string; user: { id: string; name: string } }> }
  } | null
}

export type MyEventsQueryVariables = Exact<{ [key: string]: never }>

export type MyEventsQuery = {
  me: {
    registeredEvents: Array<EventFieldsFragment>
    organizedEvents: Array<EventFieldsFragment>
  } | null
}

export type ListVenuesQueryVariables = Exact<{ [key: string]: never }>

export type ListVenuesQuery = {
  venues: Array<{ id: string; name: string; address: string; city: string; capacity: number }>
}
