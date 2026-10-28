/**
 * The tokens live here (and in localStorage) rather than in the auth store, so the
 * HTTP clients can read them without importing Pinia. The access token authorises
 * requests for an hour; the refresh token is exchanged for a new pair when it expires.
 *
 * Every tab shares the stored pair, so it is read from localStorage each time rather
 * than cached: once one tab rotates the refresh token, the old one is dead, and
 * presenting it would make the API revoke the login everywhere.
 */
const ACCESS_KEY = 'gather.token'
const REFRESH_KEY = 'gather.refreshToken'

/** This tab's copy, used when localStorage is unavailable (private mode, blocked storage). */
const memory = new Map<string, string | null>()
/** Keys whose last write didn't reach localStorage, so storage holds a stale value for them. */
const unsaved = new Set<string>()
memory.set(ACCESS_KEY, read(ACCESS_KEY))
memory.set(REFRESH_KEY, read(REFRESH_KEY))

/** Bumped whenever the session ends, so work started for the old session can tell. */
let generation = 0

function read(key: string): string | null {
  if (unsaved.has(key)) return memory.get(key) ?? null
  try {
    return localStorage.getItem(key)
  } catch {
    return memory.get(key) ?? null
  }
}

function write(key: string, value: string | null): void {
  memory.set(key, value)
  try {
    if (value) localStorage.setItem(key, value)
    else localStorage.removeItem(key)
    unsaved.delete(key)
  } catch {
    // Storage can be unavailable (private mode); the tokens still work for this tab.
    unsaved.add(key)
  }
}

export function getToken(): string | null {
  return read(ACCESS_KEY)
}

export function setToken(value: string | null): void {
  write(ACCESS_KEY, value)
}

export function getRefreshToken(): string | null {
  return read(REFRESH_KEY)
}

export function setRefreshToken(value: string | null): void {
  write(REFRESH_KEY, value)
}

/** Stores a pair from a login, a refresh or an e-mail confirmation. */
export function setTokens(pair: { token: string; refresh_token: string }): void {
  setToken(pair.token)
  setRefreshToken(pair.refresh_token)
}

export function clearTokens(): void {
  generation++
  setToken(null)
  setRefreshToken(null)
}

/** Changes every time the session is cleared (logout, a refused refresh, an expired session). */
export function sessionGeneration(): number {
  return generation
}

/**
 * Calls `handler` when another tab logs in (true) or out (false). Rotations are
 * ignored: the new pair is simply read from storage the next time it's needed.
 * Returns an unregister function.
 */
export function onSessionChangedElsewhere(handler: (signedIn: boolean) => void): () => void {
  const listener = (event: StorageEvent) => {
    // `key` is null when another tab cleared the whole storage.
    if (event.key === null) return handler(false)
    if (event.key !== REFRESH_KEY || !event.oldValue === !event.newValue) return
    handler(event.newValue !== null)
  }
  window.addEventListener('storage', listener)
  return () => window.removeEventListener('storage', listener)
}
