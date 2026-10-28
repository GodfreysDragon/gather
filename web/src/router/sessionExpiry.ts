import type { Router } from 'vue-router'
import { onUnauthenticated } from '@/api/session'
import { onSessionChangedElsewhere } from '@/api/token'
import { useAuthStore } from '@/stores/auth'

/**
 * When the API rejects our token (expired, or revoked from another device), forget
 * the session and send the user to log in, coming back to where they were after.
 * Logging in or out in another tab is followed here too.
 */
export function redirectOnUnauthenticated(router: Router): () => void {
  const toLogin = () => {
    const current = router.currentRoute.value
    // During the initial navigation the route guards decide where to go.
    if (current.matched.length === 0) return
    if (current.name === 'login' || current.name === 'register') return

    void router.replace({ name: 'login', query: { redirect: current.fullPath } })
  }

  const stopUnauthenticated = onUnauthenticated(() => {
    useAuthStore().clearSession()
    toLogin()
  })

  const stopOtherTabs = onSessionChangedElsewhere((signedIn) => {
    useAuthStore().followOtherTab(signedIn)
    // Logged out elsewhere: only pages that need an account have to go.
    if (!signedIn && router.currentRoute.value.meta.requiresAuth) toLogin()
  })

  return () => {
    stopUnauthenticated()
    stopOtherTabs()
  }
}
