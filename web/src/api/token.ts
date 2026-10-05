/**
 * The bearer token lives here (and in localStorage) rather than in the auth store,
 * so the HTTP clients can read it without importing Pinia.
 */
const STORAGE_KEY = 'gather.token'

let token: string | null = read()

function read(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

export function getToken(): string | null {
  return token
}

export function setToken(value: string | null): void {
  token = value
  try {
    if (value) localStorage.setItem(STORAGE_KEY, value)
    else localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Storage can be unavailable (private mode); the token still works for this tab.
  }
}
