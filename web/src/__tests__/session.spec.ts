import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { rest, unwrap } from '@/api/rest'
import { notifyUnauthenticated, onUnauthenticated } from '@/api/session'
import { getToken, setToken } from '@/api/token'
import { redirectOnUnauthenticated } from '@/router/sessionExpiry'
import { useAuthStore } from '@/stores/auth'

// openapi-fetch captures `fetch` when the client is created, so stub it before any import runs.
const fetchMock = vi.hoisted(() => {
  const mock = vi.fn<typeof fetch>()
  globalThis.fetch = mock
  return mock
})

function problem(status: number): Response {
  return new Response(JSON.stringify({ type: 'about:blank', title: 'Unauthenticated.', status }), {
    status,
    headers: { 'Content-Type': 'application/problem+json' },
  })
}

const user = { id: 1, name: 'Demo User', email: 'demo@gather.test' }

describe('expired sessions', () => {
  let unregister: (() => void) | undefined

  beforeEach(() => {
    setActivePinia(createPinia())
    setToken(null)
  })

  afterEach(() => {
    unregister?.()
    unregister = undefined
    fetchMock.mockReset()
  })

  it('REST reports a 401 for a request that carried a token', async () => {
    const handler = vi.fn<() => void>()
    unregister = onUnauthenticated(handler)
    setToken('expired')
    fetchMock.mockResolvedValueOnce(problem(401))

    await expect(unwrap(rest.GET('/me/registrations'))).rejects.toMatchObject({
      code: 'unauthenticated',
      status: 401,
    })
    expect(handler).toHaveBeenCalledOnce()
  })

  it('REST does not report a 401 for logging out with a dead token', async () => {
    const handler = vi.fn<() => void>()
    unregister = onUnauthenticated(handler)
    setToken('expired')
    fetchMock.mockResolvedValueOnce(problem(401))

    await expect(useAuthStore().logout()).rejects.toMatchObject({ status: 401 })
    expect(handler).not.toHaveBeenCalled()
    expect(getToken()).toBeNull()
  })

  describe('redirectOnUnauthenticated', () => {
    let router: Router

    beforeEach(async () => {
      const Page = { template: '<div />' }
      router = createRouter({
        history: createMemoryHistory(),
        routes: [
          { path: '/', name: 'events', component: Page },
          { path: '/me', name: 'my-events', component: Page, meta: { requiresAuth: true } },
          { path: '/login', name: 'login', component: Page },
        ],
      })
      unregister = redirectOnUnauthenticated(router)
    })

    it('clears the token and user and sends the user to log in, then back', async () => {
      await router.push('/me')
      const auth = useAuthStore()
      auth.user = user
      setToken('expired')

      notifyUnauthenticated()
      await flushPromises()

      expect(auth.user).toBeNull()
      expect(auth.isAuthenticated).toBe(false)
      expect(getToken()).toBeNull()
      expect(router.currentRoute.value.name).toBe('login')
      expect(router.currentRoute.value.query.redirect).toBe('/me')
    })

    it('leaves the initial navigation to the route guards', async () => {
      setToken('expired')

      notifyUnauthenticated()
      await flushPromises()

      expect(getToken()).toBeNull()
      expect(router.currentRoute.value.matched).toHaveLength(0)
    })
  })

  describe('restoreSession', () => {
    it('keeps the token and retries later when the API is unreachable', async () => {
      setToken('valid')
      const auth = useAuthStore()
      fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))

      await auth.restoreSession()
      expect(getToken()).toBe('valid')
      expect(auth.ready).toBe(false)

      fetchMock.mockResolvedValueOnce(
        new Response(JSON.stringify({ data: user }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      )
      await auth.restoreSession()
      expect(auth.user).toEqual(user)
      expect(auth.ready).toBe(true)
      expect(fetchMock).toHaveBeenCalledTimes(2)
    })

    it('drops a token the API rejects', async () => {
      setToken('expired')
      const auth = useAuthStore()
      fetchMock.mockResolvedValueOnce(problem(401))

      await auth.restoreSession()
      expect(getToken()).toBeNull()
      expect(auth.user).toBeNull()
      expect(auth.ready).toBe(true)
    })
  })
})
