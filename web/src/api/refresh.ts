import { recordApiCall } from './apiLog'
import { API_ORIGIN } from './origin'
import type { components } from './schema'
import { getRefreshToken, sessionGeneration, setRefreshToken, setTokens } from './token'

type TokenResponse = components['schemas']['TokenResponse']

/**
 * - `refreshed`: a new pair is stored (by this tab or another one); retry with it.
 * - `refused`: there's nothing to refresh with, or the API said no; the session is over.
 * - `unavailable`: offline, rate limited or the API failed; the session may still be
 *   fine, so both tokens are kept for a later attempt.
 */
export type RefreshResult = 'refreshed' | 'refused' | 'unavailable'

/** Held across tabs while one of them exchanges the shared refresh token. */
const LOCK = 'gather-token-refresh'

let inFlight: Promise<RefreshResult> | null = null

/**
 * Swaps the stored refresh token for a new token pair. Refresh tokens are single use,
 * and the API treats a second use as theft and revokes the login on every device,
 * so refreshes never overlap: concurrent callers in this tab share one request, and
 * tabs take turns through a Web Lock. A tab that waited for the lock finds the token
 * already rotated by the tab before it and uses the new pair instead.
 */
export function refreshTokens(): Promise<RefreshResult> {
  inFlight ??= refreshOnce().finally(() => {
    inFlight = null
  })
  return inFlight
}

async function refreshOnce(): Promise<RefreshResult> {
  const seen = getRefreshToken()
  if (!seen) return 'refused'
  const generation = sessionGeneration()

  const locks = typeof navigator === 'undefined' ? undefined : navigator.locks
  if (!locks) return exchange(seen, generation)

  return locks.request(LOCK, () => {
    const current = getRefreshToken()
    // Another tab rotated it (or logged out) while this one waited for the lock.
    if (current !== seen) return current ? 'refreshed' : 'refused'
    return exchange(seen, generation)
  })
}

async function exchange(refreshToken: string, generation: number): Promise<RefreshResult> {
  /** False once the session this refresh was for has ended (logout, here or in another tab). */
  const stillCurrent = () =>
    sessionGeneration() === generation && getRefreshToken() === refreshToken
  if (!stillCurrent()) return 'refused'

  const started = performance.now()
  let response: Response
  let text: string
  try {
    // Plain fetch rather than the REST client, whose middleware would retry this request too.
    response = await fetch(`${API_ORIGIN}/api/v1/auth/refresh`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    })
    text = await response.text()
  } catch {
    // Offline or the API is down: keep the refresh token for a later attempt.
    return 'unavailable'
  }

  recordApiCall({
    transport: 'REST',
    label: 'POST /auth/refresh',
    status: response.status,
    durationMs: Math.round(performance.now() - started),
    bytes: text.length,
  })

  if (!response.ok) {
    // Only a definite "no" ends the session; a 429 or 5xx might work next time.
    if (response.status !== 401 && response.status !== 422) return 'unavailable'
    if (stillCurrent()) setRefreshToken(null)
    return 'refused'
  }

  const pair = parsePair(text)
  if (!pair) return 'unavailable'
  // Logged out while the request was in flight: storing the pair would undo that.
  if (!stillCurrent()) return 'refused'

  setTokens(pair)
  return 'refreshed'
}

/** The token pair from a 200, or null if the body isn't one (e.g. a proxy's HTML page). */
function parsePair(text: string): TokenResponse | null {
  try {
    const body = JSON.parse(text) as Partial<TokenResponse> | null
    return typeof body?.token === 'string' && typeof body.refresh_token === 'string'
      ? (body as TokenResponse)
      : null
  } catch {
    return null
  }
}
