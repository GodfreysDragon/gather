import { recordApiCall } from './apiLog'
import { API_ORIGIN } from './origin'
import type { components } from './schema'
import { getRefreshToken, setRefreshToken, setTokens } from './token'

type TokenResponse = components['schemas']['TokenResponse']

let inFlight: Promise<boolean> | null = null

/**
 * Swaps the stored refresh token for a new token pair. Concurrent callers share one
 * request: refresh tokens are single use, so two parallel refreshes would have the
 * API treat the second as a stolen token and revoke the login.
 *
 * Resolves to false when there's nothing to refresh with or the API refuses (the
 * refresh token expired or was revoked); the caller then treats the session as over.
 */
export function refreshTokens(): Promise<boolean> {
  inFlight ??= exchange().finally(() => {
    inFlight = null
  })
  return inFlight
}

async function exchange(): Promise<boolean> {
  const refreshToken = getRefreshToken()
  if (!refreshToken) return false

  const started = performance.now()
  let response: Response
  try {
    // Plain fetch rather than the REST client, whose middleware would retry this request too.
    response = await fetch(`${API_ORIGIN}/api/v1/auth/refresh`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    })
  } catch {
    // Offline or the API is down: keep the refresh token for a later attempt.
    return false
  }

  const text = await response.text()
  recordApiCall({
    transport: 'REST',
    label: 'POST /auth/refresh',
    status: response.status,
    durationMs: Math.round(performance.now() - started),
    bytes: text.length,
  })

  if (!response.ok) {
    // Only a definite "no" ends the session; a 429 or 5xx might work next time.
    if (response.status === 401 || response.status === 422) setRefreshToken(null)
    return false
  }

  setTokens(JSON.parse(text) as TokenResponse)
  return true
}
