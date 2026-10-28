import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { rest, unwrap } from '@/api/rest'
import type { components } from '@/api/schema'
import { clearTokens, getToken, setTokens } from '@/api/token'

type User = components['schemas']['User']
type TokenResponse = components['schemas']['TokenResponse']

/** The query parameters of the link in a confirmation e-mail. */
export interface VerificationLink {
  id: number
  hash: string
  expires: number
  signature: string
}

/**
 * Authentication always goes through REST: it's where tokens are issued. The same
 * bearer token then authorises both REST and GraphQL requests, and both clients
 * refresh it transparently when it expires.
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
      }
    })().finally(() => {
      // Here rather than inside, which may run before `restoring` is even assigned.
      restoring = null
    })
    return restoring
  }

  /** Forgets the tokens and user locally, e.g. after the API rejected the token. */
  function clearSession(): void {
    clearTokens()
    user.value = null
  }

  /** Another tab logged in or out. The tokens are shared already; catch the user up. */
  function followOtherTab(signedIn: boolean): void {
    if (!signedIn) {
      clearSession()
      return
    }
    ready.value = false
    void restoreSession()
  }

  function startSession(response: TokenResponse): void {
    setTokens(response)
    user.value = response.user
  }

  async function login(email: string, password: string): Promise<void> {
    startSession(
      await unwrap(rest.POST('/auth/login', { body: { email, password, device_name: 'web' } })),
    )
  }

  /**
   * Starts an account. Nothing is logged in yet: the API e-mails a confirmation link,
   * and resolves to the message to show ("check your inbox").
   */
  async function register(name: string, email: string, password: string): Promise<string> {
    return (await unwrap(rest.POST('/auth/register', { body: { name, email, password } }))).message
  }

  /** Completes registration from the e-mailed link, which also logs the user in. */
  async function verifyEmail(link: VerificationLink): Promise<void> {
    startSession(
      await unwrap(
        rest.POST('/auth/email/verify/{id}/{hash}', {
          params: {
            path: { id: link.id, hash: link.hash },
            query: { expires: link.expires, signature: link.signature },
          },
          body: { device_name: 'web' },
        }),
      ),
    )
  }

  async function resendVerification(email: string): Promise<string> {
    return (await unwrap(rest.POST('/auth/email/resend', { body: { email } }))).message
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
    followOtherTab,
    login,
    register,
    verifyEmail,
    resendVerification,
    logout,
  }
})
