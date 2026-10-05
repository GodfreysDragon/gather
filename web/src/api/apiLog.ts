import { reactive } from 'vue'

/**
 * A small in-memory log of API calls, rendered by the "API traffic" panel so you
 * can watch which transport each screen uses and what it costs.
 */
export interface ApiLogEntry {
  id: number
  transport: 'REST' | 'GraphQL'
  /** "GET /events?page=2" or the GraphQL operation name. */
  label: string
  status: number | 'error'
  durationMs: number
  bytes: number
  at: Date
}

const MAX_ENTRIES = 30
let nextId = 1

export const apiLog = reactive<{ entries: ApiLogEntry[] }>({ entries: [] })

export function recordApiCall(entry: Omit<ApiLogEntry, 'id' | 'at'>): void {
  apiLog.entries.unshift({ ...entry, id: nextId++, at: new Date() })
  apiLog.entries.splice(MAX_ENTRIES)
}

export function clearApiLog(): void {
  apiLog.entries.splice(0)
}
