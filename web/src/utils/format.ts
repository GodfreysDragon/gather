const dayFormat = new Intl.DateTimeFormat(undefined, {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  year: 'numeric',
})
const timeFormat = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' })

/** "Sat, 1 Nov 2026 · 18:00–21:00" in the viewer's locale and time zone. */
export function formatEventTime(start: Date, end: Date): string {
  const sameDay = start.toDateString() === end.toDateString()
  return sameDay
    ? `${dayFormat.format(start)} · ${timeFormat.format(start)}–${timeFormat.format(end)}`
    : `${dayFormat.format(start)} ${timeFormat.format(start)} – ${dayFormat.format(end)} ${timeFormat.format(end)}`
}

export function formatDay(date: Date): { month: string; day: string } {
  return {
    month: date.toLocaleString(undefined, { month: 'short' }).toUpperCase(),
    day: String(date.getDate()),
  }
}

/** Value for <input type="datetime-local"> in the viewer's time zone. */
export function toLocalInput(date: Date): string {
  const offset = date.getTimezoneOffset() * 60_000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

export function formatBytes(bytes: number): string {
  return bytes < 1024 ? `${bytes} B` : `${(bytes / 1024).toFixed(1)} kB`
}
