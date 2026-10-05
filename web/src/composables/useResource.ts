import { storeToRefs } from 'pinia'
import { ref, shallowRef, watch, type WatchSource } from 'vue'
import { ApiError } from '@/api/errors'
import type { EventsRepository } from '@/domain/repository'
import { useTransportStore } from '@/stores/transport'

/**
 * Loads data through the active repository and reloads it whenever the given
 * sources change, or when the transport is switched between REST and GraphQL.
 */
export function useResource<T>(
  load: (repository: EventsRepository) => Promise<T>,
  sources: WatchSource[] = [],
) {
  const { repository } = storeToRefs(useTransportStore())
  const data = shallowRef<T | null>(null)
  const error = ref<ApiError | null>(null)
  const loading = ref(false)
  let latest = 0

  async function reload(): Promise<void> {
    const call = ++latest
    loading.value = true
    error.value = null
    try {
      const result = await load(repository.value)
      if (call === latest) data.value = result
    } catch (e) {
      if (call === latest)
        error.value = e instanceof ApiError ? e : new ApiError('unknown', String(e))
    } finally {
      if (call === latest) loading.value = false
    }
  }

  watch([repository, ...sources], reload, { immediate: true, deep: true })

  return { data, error, loading, reload }
}
