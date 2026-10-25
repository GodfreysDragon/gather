import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import TransportToggle from '@/components/TransportToggle.vue'
import { useTransportStore } from '@/stores/transport'

describe('TransportToggle', () => {
  let pinia: Pinia

  beforeEach(() => {
    localStorage.clear()
    pinia = createPinia()
    setActivePinia(pinia)
  })

  const render = () => mount(TransportToggle, { global: { plugins: [pinia] } })
  const radio = (wrapper: ReturnType<typeof render>, value: string) =>
    wrapper.get<HTMLInputElement>(`[data-testid="transport-${value}"]`)

  it('is a labelled group of native radios, so arrow keys and screen readers work', () => {
    const wrapper = render()
    const inputs = wrapper.findAll<HTMLInputElement>('input')

    expect(wrapper.get('fieldset legend').text()).toBe('API transport')
    expect(inputs.map((input) => input.element.type)).toEqual(['radio', 'radio'])
    expect(new Set(inputs.map((input) => input.element.name)).size).toBe(1)
    expect(inputs.map((input) => input.element.labels?.[0]?.textContent?.trim())).toEqual([
      'REST',
      'GraphQL',
    ])
  })

  it('checks the current transport', () => {
    const wrapper = render()
    expect(radio(wrapper, 'rest').element.checked).toBe(true)
    expect(radio(wrapper, 'graphql').element.checked).toBe(false)
  })

  it('switches the transport when a radio is selected (click, Space or an arrow key)', async () => {
    const wrapper = render()
    await radio(wrapper, 'graphql').setValue()

    expect(useTransportStore().transport).toBe('graphql')
    expect(radio(wrapper, 'rest').element.checked).toBe(false)
    expect(localStorage.getItem('gather.transport')).toBe('graphql')
  })

  it('follows the store when the transport changes elsewhere', async () => {
    const wrapper = render()
    useTransportStore().transport = 'graphql'
    await wrapper.vm.$nextTick()

    expect(radio(wrapper, 'graphql').element.checked).toBe(true)
  })
})
