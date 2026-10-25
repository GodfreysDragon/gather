import { storeToRefs } from 'pinia'
import { ref, shallowRef, watch, type WatchSource } from 'vue'
import { toApiError, type ApiError } from '@/api/errors'
import type { EventsRepository } from '@/domain/repository'
import { useTransportStore } from '@/stores/transport'

export interface UseResourceOptions {
  /**
   * Keep showing the previous data while the next load is in flight instead of
   * resetting it to null, e.g. so a list can dim rather than flash empty.
   */
  keepPreviousData?: boolean
}

/**
 * Loads data through the active repository and reloads it whenever the given
 * sources change, or when the transport is switched between REST and GraphQL.
 * Pass the signed-in user's id as a source when the result depends on the viewer.
 *
 * Only the latest load wins: a slow response for an earlier source value never
 * overwrites a newer one.
 */
export function useResource<T>(
  load: (repository: EventsRepository) => Promise<T>,
  sources: WatchSource[] = [],
  options: UseResourceOptions = {},
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
      if (call === latest) error.value = toApiError(e)
    } finally {
      if (call === latest) loading.value = false
    }
  }

  watch(
    [repository, ...sources],
    () => {
      if (!options.keepPreviousData) data.value = null
      void reload()
    },
    { immediate: true },
  )

  return { data, error, loading, reload }
}
