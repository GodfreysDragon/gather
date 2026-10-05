import { describe, expect, it } from 'vitest'
import { mount, RouterLinkStub } from '@vue/test-utils'
import EventCard from '@/components/EventCard.vue'
import type { EventItem } from '@/domain/types'

function makeEvent(overrides: Partial<EventItem> = {}): EventItem {
  return {
    id: 1,
    title: 'Vue meetup',
    description: '',
    startsAt: new Date('2026-11-01T18:00:00Z'),
    endsAt: new Date('2026-11-01T21:00:00Z'),
    status: 'published',
    capacity: 20,
    registrationCount: 5,
    spotsLeft: 15,
    viewer: { isRegistered: false, isOrganizer: false },
    venue: { id: 1, name: 'Loft', address: '1 Main St', city: 'Berlin', capacity: 60 },
    organizer: { id: 2, name: 'Ada' },
    ...overrides,
  }
}

function render(event: EventItem) {
  return mount(EventCard, { props: { event }, global: { stubs: { RouterLink: RouterLinkStub } } })
}

describe('EventCard', () => {
  it('shows remaining spots', () => {
    expect(render(makeEvent()).get('[data-testid="spots-left"]').text()).toBe('15 of 20 left')
  })

  it('shows "Full" when no spots are left', () => {
    const wrapper = render(makeEvent({ registrationCount: 20, spotsLeft: 0 }))
    expect(wrapper.get('[data-testid="spots-left"]').text()).toBe('Full')
  })

  it('badges drafts and events the viewer is going to', () => {
    const wrapper = render(
      makeEvent({ status: 'draft', viewer: { isRegistered: true, isOrganizer: false } }),
    )
    expect(wrapper.text()).toContain('Draft')
    expect(wrapper.text()).toContain('Going')
  })
})
