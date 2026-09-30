import type {
  EstimateStatus,
  HistoryReviewStatus,
  InvoiceStatus,
  JobStatus,
  JobType,
  TakeoffStatus,
  Tone,
} from '@/types'

/**
 * Single source of truth for the dot/marker colour of each tone. Shared by
 * `StatusChip` and `StatusDot` so the two can never drift.
 */
export const TONE_DOT_CLASS: Record<Tone, string> = {
  brand: 'bg-brand',
  success: 'bg-status-success',
  warning: 'bg-status-warning',
  danger: 'bg-status-danger',
  info: 'bg-status-info',
  purple: 'bg-status-purple',
  blue: 'bg-status-blue',
  neutral: 'bg-status-neutral',
}

/** Maps a domain status onto the shared visual tone scale. */
export const TAKEOFF_STATUS_TONE: Record<TakeoffStatus, Tone> = {
  completed: 'success',
  converted: 'info',
  draft: 'warning',
  processing: 'brand',
  failed: 'danger',
}

export const TAKEOFF_STATUS_LABEL: Record<TakeoffStatus, string> = {
  completed: 'Completed',
  converted: 'Converted',
  draft: 'Draft',
  processing: 'Processing',
  failed: 'Failed',
}

/**
 * `status` and `review_status` read together, for the history table's own
 * Review Status column — richer than `TAKEOFF_STATUS_TONE` alone once a
 * review has actually started.
 */
export const HISTORY_STATUS_TONE: Record<HistoryReviewStatus, Tone> = {
  draft: 'neutral',
  processing: 'brand',
  'ready-for-review': 'warning',
  completed: 'success',
  converted: 'info',
  failed: 'danger',
}

export const HISTORY_STATUS_LABEL: Record<HistoryReviewStatus, string> = {
  draft: 'Draft',
  processing: 'Processing',
  'ready-for-review': 'Ready for Review',
  completed: 'Completed',
  converted: 'Converted',
  failed: 'Failed',
}

/**
 * One colour per status, and completed is always green. Seven statuses, seven
 * tones — no two share a colour, so a row is readable at a glance.
 */
export const JOB_STATUS_TONE: Record<JobStatus, Tone> = {
  draft: 'neutral',
  planning: 'purple',
  scheduled: 'info',
  'in-progress': 'blue',
  'on-hold': 'warning',
  delayed: 'danger',
  completed: 'success',
}

export const JOB_STATUS_LABEL: Record<JobStatus, string> = {
  draft: 'Draft',
  planning: 'Planning',
  scheduled: 'Scheduled',
  'in-progress': 'In Progress',
  'on-hold': 'On Hold',
  delayed: 'Delayed',
  completed: 'Completed',
}

export const JOB_TYPE_LABEL: Record<JobType, string> = {
  residential: 'Residential',
  commercial: 'Commercial',
  industrial: 'Industrial',
}

export const ESTIMATE_STATUS_TONE: Record<EstimateStatus, Tone> = {
  draft: 'neutral',
  sent: 'info',
  approved: 'success',
  rejected: 'danger',
}

export const ESTIMATE_STATUS_LABEL: Record<EstimateStatus, string> = {
  draft: 'Draft',
  sent: 'Approval Pending',
  approved: 'Approved',
  rejected: 'Rejected',
}

export const INVOICE_STATUS_TONE: Record<InvoiceStatus, Tone> = {
  draft: 'neutral',
  sent: 'blue',
  paid: 'success',
  overdue: 'danger',
}

/** `sent` is called what it is: sent to the client, and not yet paid or overdue. */
export const INVOICE_STATUS_LABEL: Record<InvoiceStatus, string> = {
  draft: 'Draft',
  sent: 'Sent',
  paid: 'Paid',
  overdue: 'Overdue',
}

/** Confidence buckets shared by the legend table and confidence meters. */
export function confidenceTone(confidence: number): Tone {
  if (confidence >= 0.9) return 'success'
  if (confidence >= 0.75) return 'brand'
  if (confidence >= 0.6) return 'warning'
  return 'danger'
}

export function confidenceLabel(confidence: number): string {
  if (confidence >= 0.9) return 'High'
  if (confidence >= 0.75) return 'Good'
  if (confidence >= 0.6) return 'Review'
  return 'Low'
}
