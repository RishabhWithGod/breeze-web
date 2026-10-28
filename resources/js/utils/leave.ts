import type { LeaveRequestStatus, LeaveStatus, Tone } from '@/types'
import { formatCalendarDate } from './format'

export interface LeaveLabel {
  readonly tone: Tone
  /** The one-line answer: "On leave · available in 3 days". */
  readonly label: string
  /** The date behind it, when there is one: "Back Nov 7". */
  readonly detail: string | null
}

const days = (count: number): string => `${count} ${count === 1 ? 'day' : 'days'}`

/**
 * What a person's availability reads as. The count comes from the server, worked out from
 * today's date, so it is already one lower than yesterday — nothing here counts anything.
 */
export function describeLeave(status: LeaveStatus | null): LeaveLabel | null {
  if (status === null) return null

  if (status.state === 'on_leave' && status.daysLeft !== null) {
    return {
      tone: 'warning',
      label:
        status.daysLeft === 1 ? 'On leave · available tomorrow' : `On leave · available in ${days(status.daysLeft)}`,
      detail: status.availableOn ? `Back ${formatCalendarDate(status.availableOn, 'MMM d')}` : null,
    }
  }

  if (status.state === 'upcoming' && status.startsIn !== null) {
    return {
      tone: 'info',
      label: status.startsIn === 1 ? 'Leave starts tomorrow' : `Leave starts in ${days(status.startsIn)}`,
      detail:
        status.startsOn && status.endsOn
          ? `${formatCalendarDate(status.startsOn, 'MMM d')} – ${formatCalendarDate(status.endsOn, 'MMM d')}`
          : null,
    }
  }

  if (status.state === 'pending') {
    return { tone: 'neutral', label: 'Leave requested', detail: null }
  }

  return null
}

export const LEAVE_STATUS_LABEL: Record<LeaveRequestStatus, { readonly label: string; readonly tone: Tone }> = {
  pending: { label: 'Waiting', tone: 'warning' },
  approved: { label: 'Approved', tone: 'success' },
  rejected: { label: 'Not approved', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
}

/** "Nov 4 – Nov 6 · 3 days", or "Nov 4 · 1 day". */
export function leaveRange(startDate: string, endDate: string, count: number): string {
  const first = formatCalendarDate(startDate, 'MMM d')
  const span = startDate === endDate ? first : `${first} – ${formatCalendarDate(endDate, 'MMM d')}`

  return `${span} · ${days(count)}`
}
