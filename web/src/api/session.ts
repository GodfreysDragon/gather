/**
 * Bridges "the API says this token is no longer valid" from the HTTP clients to
 * whoever owns the session (the auth store and the router), without the clients
 * importing either of them.
 */
type Listener = () => void

let listener: Listener | null = null

/** Registers the single handler for expired or revoked tokens. Returns an unregister function. */
export function onUnauthenticated(handler: Listener): () => void {
  listener = handler
  return () => {
    if (listener === handler) listener = null
  }
}

/** Called by the HTTP clients when an authenticated request is rejected with 401. */
export function notifyUnauthenticated(): void {
  listener?.()
}
