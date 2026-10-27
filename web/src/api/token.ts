/**
 * The tokens live here (and in localStorage) rather than in the auth store, so the
 * HTTP clients can read them without importing Pinia. The access token authorises
 * requests for an hour; the refresh token is exchanged for a new pair when it expires.
 */
const ACCESS_KEY = 'gather.token'
const REFRESH_KEY = 'gather.refreshToken'

let token: string | null = read(ACCESS_KEY)
let refreshToken: string | null = read(REFRESH_KEY)

function read(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

function write(key: string, value: string | null): void {
  try {
    if (value) localStorage.setItem(key, value)
    else localStorage.removeItem(key)
  } catch {
    // Storage can be unavailable (private mode); the tokens still work for this tab.
  }
}

export function getToken(): string | null {
  return token
}

export function setToken(value: string | null): void {
  token = value
  write(ACCESS_KEY, value)
}

export function getRefreshToken(): string | null {
  return refreshToken
}

export function setRefreshToken(value: string | null): void {
  refreshToken = value
  write(REFRESH_KEY, value)
}

/** Stores a pair from a login, a refresh or an e-mail confirmation. */
export function setTokens(pair: { token: string; refresh_token: string }): void {
  setToken(pair.token)
  setRefreshToken(pair.refresh_token)
}

export function clearTokens(): void {
  setToken(null)
  setRefreshToken(null)
}
