export function formatBytes(bytes: number | null | undefined, digits = 1): string {
  if (bytes === null || bytes === undefined || Number.isNaN(bytes)) return '—'
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = bytes / 1024
  let i = 0
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024
    i++
  }
  return `${value.toFixed(value >= 100 ? 0 : digits)} ${units[i]}`
}

export function formatPercent(value: number | null | undefined, digits = 0): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return `${value.toFixed(digits)}%`
}

export function percentOf(used: number | null | undefined, total: number | null | undefined): number | null {
  if (!used || !total) return used === 0 && total ? 0 : null
  return Math.min(100, (used / total) * 100)
}

export function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return '—'
  if (seconds < 60) return `${Math.round(seconds)}s`
  const m = Math.floor(seconds / 60)
  const s = Math.round(seconds % 60)
  if (m < 60) return s ? `${m}m ${s}s` : `${m}m`
  const h = Math.floor(m / 60)
  return `${h}h ${m % 60}m`
}

export function formatUptime(seconds: number | null | undefined): string {
  if (!seconds && seconds !== 0) return '—'
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  if (days > 0) return `${days} day${days === 1 ? '' : 's'}${hours ? ` ${hours}h` : ''}`
  const minutes = Math.floor((seconds % 3600) / 60)
  return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`
}

const rtf = typeof Intl !== 'undefined' ? new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }) : null

export function timeAgo(iso: string | null | undefined): string {
  if (!iso) return '—'
  const diff = (new Date(iso).getTime() - Date.now()) / 1000
  const abs = Math.abs(diff)
  if (!rtf) return new Date(iso).toLocaleString()
  if (abs < 45) return 'just now'
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour')
  if (abs < 86400 * 30) return rtf.format(Math.round(diff / 86400), 'day')
  return new Date(iso).toLocaleDateString()
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

export function formatTime(iso: string | null | undefined): string {
  if (!iso) return ''
  return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

export function formatRate(bytesPerSecond: number | null | undefined): string {
  if (bytesPerSecond === null || bytesPerSecond === undefined) return '—'
  return `${formatBytes(bytesPerSecond)}/s`
}

export function classNames(...values: Array<string | false | null | undefined>): string {
  return values.filter(Boolean).join(' ')
}
