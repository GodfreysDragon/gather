import type { Router } from 'vue-router'
import { onUnauthenticated } from '@/api/session'
import { useAuthStore } from '@/stores/auth'

/**
 * When the API rejects our token (expired, or revoked from another device), forget
 * the session and send the user to log in, coming back to where they were after.
 */
export function redirectOnUnauthenticated(router: Router): () => void {
  return onUnauthenticated(() => {
    useAuthStore().clearSession()

    const current = router.currentRoute.value
    // During the initial navigation the route guards decide where to go.
    if (current.matched.length === 0) return
    if (current.name === 'login' || current.name === 'register') return

    void router.replace({ name: 'login', query: { redirect: current.fullPath } })
  })
}
