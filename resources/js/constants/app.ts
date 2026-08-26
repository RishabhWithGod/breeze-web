export const APP_NAME = 'Breeze AI'

/** Shared page-transition + entrance timings (seconds). */
export const MOTION = {
  fast: 0.18,
  base: 0.28,
  slow: 0.45,
  stagger: 0.06,
} as const

/** Rows per page. Must match `takeoff.per_page` in config/takeoff.php. */
export const PAGE_SIZE = 5

/** The six steps a drawing passes through, in order. */
export const WORKFLOW_STEPS = [
  { key: 'upload', label: 'Upload' },
  { key: 'analysis', label: 'Analysis' },
  { key: 'review', label: 'Review' },
  { key: 'estimate', label: 'Estimate' },
  { key: 'job', label: 'Job' },
  { key: 'schedule', label: 'Schedule' },
] as const

export type WorkflowStep = (typeof WORKFLOW_STEPS)[number]['key']

/** The stages a takeoff passes through, as the Workflow Progress card shows them. */
export const WORKFLOW_STAGES = [
  { key: 'analysis', label: 'AI Analysis' },
  { key: 'review', label: 'Review' },
  { key: 'estimate', label: 'Estimate' },
  { key: 'job', label: 'Job' },
  { key: 'schedule', label: 'Schedule' },
  { key: 'complete', label: 'Complete' },
] as const

export type WorkflowStage = (typeof WORKFLOW_STAGES)[number]['key']
