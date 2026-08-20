import type {
  AssignmentRole,
  EstimateCategory,
  ReviewFilter,
  ReviewStatus,
  SelectOption,
  Tone,
} from '@/types'

/** Filter chips above the symbol grid. Values match SymbolReview::scopeStatus. */
export const REVIEW_FILTERS: readonly { value: ReviewFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'modified', label: 'Edited' },
  { value: 'known', label: 'Recognised' },
  { value: 'unknown', label: 'Unrecognised' },
  // The engine's own signals, in the reviewer's words.
  { value: 'needs-review', label: 'Flagged' },
  { value: 'ai-rejected', label: 'Discarded by AI' },
]

export const REVIEW_SORT_OPTIONS: readonly SelectOption[] = [
  { value: 'position', label: 'Drawing order' },
  { value: 'confidence-desc', label: 'Best match first' },
  { value: 'confidence-asc', label: 'Weakest match first' },
  { value: 'name-asc', label: 'Symbol name: A–Z' },
]

export const REVIEW_STATUS_TONE: Record<ReviewStatus, Tone> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

export const REVIEW_STATUS_LABEL: Record<ReviewStatus, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
}

/** Pipeline stages a crop passes through, in the order the card shows them. */
export const PIPELINE_STAGES: readonly { key: string; label: string }[] = [
  { key: 'generated', label: 'Generated' },
  { key: 'validated', label: 'Validated' },
  { key: 'classified', label: 'Classified' },
  { key: 'fusion', label: 'Fusion' },
  { key: 'final_json', label: 'Final JSON' },
]

/** Detector badges, in the order the reference product lists them. */
export const DETECTION_SOURCES: readonly {
  key: 'template' | 'vector' | 'vision' | 'ocr'
  label: string
  tone: Tone
}[] = [
  { key: 'template', label: 'Template', tone: 'info' },
  { key: 'vector', label: 'Vector', tone: 'brand' },
  { key: 'vision', label: 'Vision', tone: 'warning' },
  { key: 'ocr', label: 'OCR', tone: 'neutral' },
]

/** Sort options for the final symbol table. Values match FinalSymbol::SORTS. */
export const FINAL_SORT_OPTIONS: readonly SelectOption[] = [
  { value: 'count-desc', label: 'Count: high to low' },
  { value: 'count-asc', label: 'Count: low to high' },
  { value: 'name-asc', label: 'Symbol: A–Z' },
  { value: 'name-desc', label: 'Symbol: Z–A' },
  { value: 'confidence-desc', label: 'Confidence: high to low' },
  { value: 'confidence-asc', label: 'Confidence: low to high' },
]

export const FINAL_SOURCE_OPTIONS: readonly SelectOption[] = [
  { value: 'all', label: 'All sources' },
  { value: 'template', label: 'Template' },
  { value: 'vector', label: 'Vector' },
  { value: 'vision', label: 'Vision' },
  { value: 'ocr', label: 'OCR' },
]

export const ESTIMATE_CATEGORY_LABEL: Record<EstimateCategory, string> = {
  material: 'Materials',
  fixture: 'Fixtures',
  labor: 'Labor',
  equipment: 'Equipment',
}

/** Roles a job can be staffed with. Values match JobAssignment::ROLES. */
export const ASSIGNMENT_ROLES: readonly { value: AssignmentRole; label: string }[] = [
  { value: 'estimator', label: 'Estimator' },
  { value: 'project-manager', label: 'Project Manager' },
  { value: 'foreman', label: 'Foreman' },
  { value: 'electrician', label: 'Electrician' },
  { value: 'reviewer', label: 'Reviewer' },
]

/** Tone per audit action, used by the approval history list. */
export const HISTORY_TONE: Record<string, Tone> = {
  ai_response_received: 'info',
  lifecycle_attached: 'info',
  engine_learned: 'success',
  engine_declined: 'warning',
  approved: 'success',
  bulk_approve: 'success',
  rejected: 'danger',
  bulk_reject: 'danger',
  reset: 'neutral',
  bulk_reset: 'neutral',
  count_changed: 'warning',
  renamed: 'warning',
  merged: 'warning',
  split: 'warning',
  note_added: 'neutral',
  final_json_generated: 'brand',
  review_reopened: 'warning',
  job_created: 'brand',
  estimate_created: 'brand',
  estimate_updated: 'info',
  estimate_line_added: 'info',
  estimate_line_updated: 'info',
  estimate_line_removed: 'danger',
  assignment_added: 'info',
  assignment_released: 'neutral',

  // Time entry activity trail (`TimeEntryActivity::type`).
  created: 'brand',
  submitted: 'info',
  locked: 'neutral',
}
