import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { graphqlRepository } from '@/domain/graphqlRepository'
import type { EventsRepository, Transport } from '@/domain/repository'
import { restRepository } from '@/domain/restRepository'

const STORAGE_KEY = 'gather.transport'

function initialTransport(): Transport {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'graphql' ? 'graphql' : 'rest'
  } catch {
    return 'rest'
  }
}

/**
 * Which API the UI talks to. Views get their data through `repository`, so flipping
 * this swaps every screen between REST and GraphQL without touching them.
 */
export const useTransportStore = defineStore('transport', () => {
  const transport = ref<Transport>(initialTransport())

  const repository = computed<EventsRepository>(() =>
    transport.value === 'graphql' ? graphqlRepository : restRepository,
  )

  watch(transport, (value) => {
    try {
      localStorage.setItem(STORAGE_KEY, value)
    } catch {
      // Not persisted; the choice still applies for this session.
    }
  })

  return { transport, repository }
})
