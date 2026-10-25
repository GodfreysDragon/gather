import { beforeEach, describe, expect, it } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { effectScope, nextTick, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { useResource } from '@/composables/useResource'

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

/** Runs useResource with a loader whose calls the test settles by hand. */
function setup(options: { keepPreviousData?: boolean } = {}) {
  const id = ref(1)
  const calls: { id: number; result: ReturnType<typeof deferred<string>> }[] = []
  const scope = effectScope()
  const resource = scope.run(() =>
    useResource(
      () => {
        const result = deferred<string>()
        calls.push({ id: id.value, result })
        return result.promise
      },
      [id],
      options,
    ),
  )!
  return { id, calls, resource, scope }
}

describe('useResource', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('loads immediately and exposes the result', async () => {
    const { calls, resource } = setup()
    expect(resource.loading.value).toBe(true)

    calls[0]!.result.resolve('one')
    await flushPromises()

    expect(resource.data.value).toBe('one')
    expect(resource.loading.value).toBe(false)
  })

  it('ignores a response that arrives after a newer request was made', async () => {
    const { id, calls, resource } = setup()
    id.value = 2
    await nextTick()
    expect(calls.map((call) => call.id)).toEqual([1, 2])

    calls[1]!.result.resolve('two')
    await flushPromises()
    calls[0]!.result.resolve('one')
    await flushPromises()

    expect(resource.data.value).toBe('two')
    expect(resource.loading.value).toBe(false)
  })

  it('ignores a stale failure too', async () => {
    const { id, calls, resource } = setup()
    id.value = 2
    await nextTick()

    calls[1]!.result.resolve('two')
    calls[0]!.result.reject(new ApiError('server_error', 'Boom', 500))
    await flushPromises()

    expect(resource.error.value).toBeNull()
    expect(resource.data.value).toBe('two')
  })

  it('resets data when a source changes, so the previous item is never shown as the new one', async () => {
    const { id, calls, resource } = setup()
    calls[0]!.result.resolve('one')
    await flushPromises()

    id.value = 2
    await nextTick()

    expect(resource.data.value).toBeNull()
    expect(resource.loading.value).toBe(true)
  })

  it('can keep the previous data while reloading', async () => {
    const { id, calls, resource } = setup({ keepPreviousData: true })
    calls[0]!.result.resolve('one')
    await flushPromises()

    id.value = 2
    await nextTick()

    expect(resource.data.value).toBe('one')
  })

  it('normalises thrown values into ApiErrors', async () => {
    const { calls, resource } = setup()
    calls[0]!.result.reject(new TypeError('nope'))
    await flushPromises()

    expect(resource.error.value).toBeInstanceOf(ApiError)
    expect(resource.error.value?.code).toBe('unknown')
    expect(resource.error.value?.message).toBe('nope')
  })
})
