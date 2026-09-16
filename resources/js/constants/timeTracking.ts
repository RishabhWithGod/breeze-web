import type { SelectOption, TimeEntryStatus, Tone } from '@/types'

export const TIME_ENTRY_STATUS_LABEL: Record<TimeEntryStatus, string> = {
  draft: 'Draft',
  submitted: 'Submitted',
  approved: 'Approved',
  rejected: 'Rejected',
  locked: 'Locked',
}

export const TIME_ENTRY_STATUS_TONE: Record<TimeEntryStatus, Tone> = {
  draft: 'neutral',
  submitted: 'info',
  approved: 'success',
  rejected: 'danger',
  locked: 'brand',
}

export const TIME_ENTRY_STATUS_FILTERS: readonly SelectOption[] = [
  { label: 'All Statuses', value: 'all' },
  { label: 'Draft', value: 'draft' },
  { label: 'Submitted', value: 'submitted' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Locked', value: 'locked' },
]

/** The day-grouped list's aggregate status — "worst session wins" across
 *  however many timer/manual entries make up that technician's day. */
export const DAY_STATUS_LABEL: Record<'approved' | 'pending' | 'rejected' | 'mixed', string> = {
  approved: 'Approved',
  pending: 'Pending',
  rejected: 'Needs review',
  mixed: 'Mixed',
}

export const DAY_STATUS_TONE: Record<'approved' | 'pending' | 'rejected' | 'mixed', Tone> = {
  approved: 'success',
  pending: 'info',
  rejected: 'danger',
  mixed: 'warning',
}

export const BILLABLE_FILTERS: readonly SelectOption[] = [
  { label: 'Billable & Non-billable', value: 'all' },
  { label: 'Billable only', value: 'yes' },
  { label: 'Non-billable only', value: 'no' },
]

/** Human labels for the activity trail's machine types. */
export const TIME_ENTRY_ACTIVITY_LABEL: Record<string, string> = {
  created: 'Time entry created',
  edited: 'Time entry updated',
  submitted: 'Submitted for approval',
  approved: 'Approved',
  rejected: 'Rejected',
  status_changed: 'Status changed',
  locked: 'Superseded by a correction',
}
