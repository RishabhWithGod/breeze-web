import type { EstimateStatus, JobStatus, JobType, TakeoffStatus, Tone } from '@/types'

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

export const JOB_STATUS_TONE: Record<JobStatus, Tone> = {
  draft: 'warning',
  planning: 'neutral',
  scheduled: 'info',
  'in-progress': 'success',
  'on-hold': 'warning',
  delayed: 'danger',
  completed: 'brand',
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
  draft: 'warning',
  sent: 'info',
  approved: 'success',
  rejected: 'danger',
}

export const ESTIMATE_STATUS_LABEL: Record<EstimateStatus, string> = {
  draft: 'Draft',
  sent: 'Sent',
  approved: 'Approved',
  rejected: 'Rejected',
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
