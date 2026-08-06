import { format, formatDistanceToNow, parseISO } from 'date-fns'

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

/** "2026-08-01T10:00:00Z" → "Aug 1, 2026" */
export function formatDate(iso: string, pattern = 'MMM d, yyyy'): string {
  return format(parseISO(iso), pattern)
}

/** "2026-08-01T10:00:00Z" → "about 2 hours ago" */
export function formatRelative(iso: string): string {
  return `${formatDistanceToNow(parseISO(iso))} ago`
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
