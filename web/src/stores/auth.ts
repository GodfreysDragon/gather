import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { rest, unwrap } from '@/api/rest'
import type { components } from '@/api/schema'
import { getToken, setToken } from '@/api/token'

type User = components['schemas']['User']

/**
 * Authentication always goes through REST: it's where tokens are issued. The same
 * bearer token then authorises both REST and GraphQL requests.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const ready = ref(false)

  const isAuthenticated = computed(() => user.value !== null)

  let restoring: Promise<void> | null = null

  /**
   * Loads the user for a stored token, once. Only a 401 discards the token: if the
   * API is unreachable or failing, the token is kept and `ready` stays false, so the
   * next navigation tries again instead of treating an outage as a logout.
   */
  function restoreSession(): Promise<void> {
    if (ready.value) return Promise.resolve()
    restoring ??= (async () => {
      try {
        if (getToken()) user.value = (await unwrap(rest.GET('/me'))).data
        ready.value = true
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          clearSession()
          ready.value = true
        }
      } finally {
        restoring = null
      }
    })()
    return restoring
  }

  /** Forgets the token and user locally, e.g. after the API rejected the token. */
  function clearSession(): void {
    setToken(null)
    user.value = null
  }

  async function login(email: string, password: string): Promise<void> {
    const body = await unwrap(
      rest.POST('/auth/login', { body: { email, password, device_name: 'web' } }),
    )
    setToken(body.token)
    user.value = body.user
  }

  async function register(name: string, email: string, password: string): Promise<void> {
    const body = await unwrap(
      rest.POST('/auth/register', { body: { name, email, password, device_name: 'web' } }),
    )
    setToken(body.token)
    user.value = body.user
  }

  async function logout(): Promise<void> {
    try {
      await unwrap(rest.POST('/auth/logout'))
    } finally {
      clearSession()
    }
  }

  return {
    user,
    ready,
    isAuthenticated,
    restoreSession,
    clearSession,
    login,
    register,
    logout,
  }
})
