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

  async function restoreSession(): Promise<void> {
    if (ready.value) return

    if (getToken()) {
      try {
        user.value = (await unwrap(rest.GET('/me'))).data
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) setToken(null)
      }
    }

    ready.value = true
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
      setToken(null)
      user.value = null
    }
  }

  return { user, ready, isAuthenticated, restoreSession, login, register, logout }
})
