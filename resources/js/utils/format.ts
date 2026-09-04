import { format, formatDistanceToNow, isToday, isYesterday, parseISO } from 'date-fns'

const FILE_SIZE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'] as const

/** 1536 → "1.5 KB" */
export function formatFileSize(bytes: number, fractionDigits = 1): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B'
  const exponent = Math.min(
    Math.floor(Math.log(bytes) / Math.log(1024)),
    FILE_SIZE_UNITS.length - 1,
  )
  const value = bytes / 1024 ** exponent
  const unit = FILE_SIZE_UNITS[exponent] ?? 'B'
  return `${value.toFixed(exponent === 0 ? 0 : fractionDigits)} ${unit}`
}

/**
 * A date as the United States writes one: "09/03/2026".
 *
 * Numeric and month-first everywhere a date is read as a value — a table cell,
 * a due date, a signed-off-on. Calendar headings keep their month names, since
 * "September 2026" over a month grid is a heading and not a date being read.
 */
export function formatDate(iso: string, pattern = 'MM/dd/yyyy'): string {
  return format(parseISO(iso), pattern)
}

/** "2026-08-01T10:00:00Z" → "about 2 hours ago" */
export function formatRelative(iso: string): string {
  return `${formatDistanceToNow(parseISO(iso))} ago`
}

/** "Today, 2:15 PM" / "Yesterday, 9:02 AM" / "09/03/2026, 9:02 AM" */
export function formatModified(iso: string): string {
  const date = parseISO(iso)
  if (isToday(date)) return `Today, ${format(date, 'h:mm a')}`
  if (isYesterday(date)) return `Yesterday, ${format(date, 'h:mm a')}`
  return format(date, 'MM/dd/yyyy, h:mm a')
}

/** 0.947 → "95%" */
export function formatPercent(ratio: number, fractionDigits = 0): string {
  return `${(ratio * 100).toFixed(fractionDigits)}%`
}

/** 12480 → "12,480" */
export function formatNumber(value: number): string {
  return new Intl.NumberFormat('en-US').format(value)
}

/** 12480 → "$12,480"; `formatCurrency(24850, 2)` → "$24,850.00" */
export function formatCurrency(value: number, fractionDigits = 0): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(value)
}

/** 185000 → "3m 05s" */
export function formatDuration(ms: number): string {
  const totalSeconds = Math.max(0, Math.round(ms / 1000))
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  return `${minutes}m ${String(seconds).padStart(2, '0')}s`
}

/** 9258 → "02:34:18" — the running-timer clock face. */
export function formatClock(totalSeconds: number): string {
  const seconds = Math.max(0, Math.round(totalSeconds))
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${pad(hours)}:${pad(minutes)}:${pad(secs)}`
}

/** 3.25 → "3h 15m" */
export function formatHours(hours: number): string {
  const wholeHours = Math.floor(hours)
  const minutes = Math.round((hours - wholeHours) * 60)
  if (wholeHours === 0) return `${minutes}m`
  if (minutes === 0) return `${wholeHours}h`
  return `${wholeHours}h ${minutes}m`
}

/** "Office_Building_Plans.pdf" → "PDF" */
export function getFileExtension(fileName: string): string {
  const parts = fileName.split('.')
  return parts.length > 1 ? (parts.pop() ?? '').toUpperCase() : ''
}

/** Shortens long file names while preserving the extension. */
export function truncateFileName(fileName: string, maxLength = 28): string {
  if (fileName.length <= maxLength) return fileName
  const extension = getFileExtension(fileName)
  const base = fileName.slice(0, fileName.length - extension.length - 1)
  const keep = Math.max(4, maxLength - extension.length - 4)
  return `${base.slice(0, keep)}…${extension ? `.${extension.toLowerCase()}` : ''}`
}

/**
 * "john smith" → "John Smith" — a name or place, capitalised the way it is
 * written, not forced to a single style.
 *
 * Used while typing, so only the first letter of each word is ever touched —
 * everything else is left exactly as typed. That keeps "McDonald" and
 * "O'Brien" intact where a full title-case (lowercasing the rest of each word)
 * would break them, and it never fights someone correcting a capital the
 * moment after this adds one.
 */
export function toTitleCase(value: string): string {
  return value.replace(/(^|\s)\p{Ll}/gu, (letter) => letter.toUpperCase())
}

/**
 * A phone number as the United States writes one: "(555) 123-4567".
 *
 * Used while typing, so it has to make sense half-finished — "555" stays
 * "(555", "5551234" becomes "(555) 123" — and it never rejects what is typed.
 * Whether a number is real is the server's answer, not this one's.
 */
export function formatUsPhone(value: string): string {
  const digits = value.replace(/\D/g, '')
  // A leading 1 is the country code, not part of the number.
  const local = (digits.startsWith('1') ? digits.slice(1) : digits).slice(0, 10)

  if (local.length === 0) return ''
  if (local.length <= 3) return `(${local}`
  if (local.length <= 6) return `(${local.slice(0, 3)}) ${local.slice(3)}`

  return `(${local.slice(0, 3)}) ${local.slice(3, 6)}-${local.slice(6)}`
}

/** "2026-09-07" → "09/07/2026". Anything else is passed through untouched. */
export function isoToUsDate(iso: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)

  return match ? `${match[2]}/${match[3]}/${match[1]}` : iso
}

/**
 * "09/07/2026" → "2026-09-07", and '' for anything that is not a real day.
 *
 * The calendar round-trip is the check: 02/30/2026 parses as digits but is not
 * a date, and JavaScript would quietly roll it forward to March if asked.
 */
export function usDateToIso(text: string): string {
  const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(text)

  if (!match) return ''

  const [, month, day, year] = match
  const iso = `${year}-${month}-${day}`
  const parsed = new Date(`${iso}T00:00:00`)

  return Number.isNaN(parsed.getTime()) || parsed.getDate() !== Number(day) ? '' : iso
}

/** Shapes digits into MM/DD/YYYY as they are typed, and never rejects them. */
export function maskUsDate(text: string): string {
  const digits = text.replace(/\D/g, '').slice(0, 8)

  if (digits.length <= 2) return digits
  if (digits.length <= 4) return `${digits.slice(0, 2)}/${digits.slice(2)}`

  return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`
}
