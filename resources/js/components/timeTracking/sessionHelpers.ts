import type { TimeEntry } from '@/types'
import type { SessionStatusKey } from './SessionStatus'

/** "14:02:24" → "2:02 PM". A clock time with no date has no zone to convert. */
export function clockTime(value: string | null): string {
  if (!value) return '—'

  const [hours = 0, minutes = 0] = value.split(':').map(Number)

  return `${hours % 12 === 0 ? 12 : hours % 12}:${String(minutes).padStart(2, '0')} ${hours < 12 ? 'AM' : 'PM'}`
}

/** A timer/manual entry as the status pills say it — a finished draft is waiting on a manager. */
export function entrySessionStatus(entry: Pick<TimeEntry, 'status' | 'endTime'>): SessionStatusKey {
  switch (entry.status) {
    case 'approved':
    case 'locked':
      return 'approved'
    case 'rejected':
      return 'rejected'
    case 'submitted':
      return 'pending'
    default:
      return entry.endTime !== null ? 'pending' : 'draft'
  }
}
