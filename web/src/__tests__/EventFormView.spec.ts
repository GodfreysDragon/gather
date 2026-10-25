import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import EventFormView from '@/views/EventFormView.vue'

// The venue list request has nowhere to go in tests; the form must work regardless.
vi.hoisted(() => {
  globalThis.fetch = () => Promise.reject(new TypeError('offline'))
})

const push = vi.fn<(to: unknown) => Promise<void>>()
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))

describe('EventFormView', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('flags empty dates on the client, links the message and focuses the field', async () => {
    const wrapper = mount(EventFormView, {
      attachTo: document.body,
      global: { stubs: { RouterLink: RouterLinkStub } },
    })
    await wrapper.get('#starts').setValue('')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const starts = wrapper.get('#starts')
    expect(starts.attributes('aria-invalid')).toBe('true')
    expect(starts.attributes('aria-describedby')).toBe('startsAt-error')
    expect(wrapper.get('#startsAt-error').text()).toBe('Enter a start date and time.')
    expect(wrapper.get('#ends').attributes('aria-invalid')).toBe('false')
    expect(document.activeElement).toBe(starts.element)
    expect(push).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('cancels to the events list when creating', () => {
    const wrapper = mount(EventFormView, { global: { stubs: { RouterLink: RouterLinkStub } } })
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'events' })
  })
})
