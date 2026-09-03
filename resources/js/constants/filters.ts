import type { EstimateStatus, JobStatus, SelectOption } from '@/types'

/* -------------------------------------------------------------------------- */
/*  Takeoff history                                                           */
/* -------------------------------------------------------------------------- */

export const HISTORY_FILTERS = [
  { label: 'All Clients', value: 'all' },
  { label: 'Drafts', value: 'draft' },
  { label: 'Completed', value: 'completed' },
  { label: 'Converted', value: 'converted' },
] as const

export type HistoryFilter = (typeof HISTORY_FILTERS)[number]['value']

export const HISTORY_SORT_OPTIONS = [
  { label: 'Date (Newest)', value: 'date-desc' },
  { label: 'Date (Oldest)', value: 'date-asc' },
  { label: 'Name (A–Z)', value: 'name-asc' },
] as const

export type HistorySort = (typeof HISTORY_SORT_OPTIONS)[number]['value']

/* -------------------------------------------------------------------------- */
/*  Projects                                                                  */
/* -------------------------------------------------------------------------- */

/** Mirrors Project::STATUSES, plus the "all" pseudo-filter. */
export const PROJECT_STATUS_FILTERS = [
  { label: 'All Clients', value: 'all' },
  { label: 'Draft', value: 'draft' },
  { label: 'Processing', value: 'processing' },
  { label: 'Completed', value: 'completed' },
  { label: 'Converted', value: 'converted' },
  { label: 'Failed', value: 'failed' },
] as const

export type ProjectStatusFilter = (typeof PROJECT_STATUS_FILTERS)[number]['value']

/** Mirrors Project::SORTS. */
export const PROJECT_SORT_OPTIONS = [
  { label: 'Recently added', value: 'recent' },
  { label: 'Oldest first', value: 'oldest' },
  { label: 'Name (A–Z)', value: 'name-asc' },
  { label: 'Due date (Soonest)', value: 'due-asc' },
  { label: 'Drawings (Most)', value: 'documents-desc' },
] as const

export type ProjectSort = (typeof PROJECT_SORT_OPTIONS)[number]['value']

/* -------------------------------------------------------------------------- */
/*  Jobs                                                                      */
/* -------------------------------------------------------------------------- */

/** Mirrors Job::STATUSES, plus the "all" pseudo-filter. */
export const JOB_STATUS_FILTERS = [
  { label: 'All Jobs', value: 'all' },
  { label: 'Draft', value: 'draft' },
  { label: 'Planning', value: 'planning' },
  { label: 'Scheduled', value: 'scheduled' },
  { label: 'In Progress', value: 'in-progress' },
  { label: 'On Hold', value: 'on-hold' },
  { label: 'Delayed', value: 'delayed' },
  { label: 'Completed', value: 'completed' },
] as const

/** Mirrors Job::SORTS. */
export const JOB_SORT_OPTIONS = [
  { label: 'Recently added', value: 'recent' },
  { label: 'Name (A–Z)', value: 'name-asc' },
  { label: 'Start date (Earliest)', value: 'start-asc' },
  { label: 'Start date (Latest)', value: 'start-desc' },
  { label: 'Budget (Highest)', value: 'budget-desc' },
  { label: 'Budget (Lowest)', value: 'budget-asc' },
] as const

export type JobSort = (typeof JOB_SORT_OPTIONS)[number]['value']

/** Archive visibility. */
export const JOB_VIEW_OPTIONS = [
  { label: 'Active', value: 'active' },
  { label: 'Archived', value: 'archived' },
  { label: 'All', value: 'all' },
] as const

export type JobView = (typeof JOB_VIEW_OPTIONS)[number]['value']

export const JOB_TYPE_FILTERS = [
  { label: 'All Types', value: 'all' },
  { label: 'Residential', value: 'residential' },
  { label: 'Commercial', value: 'commercial' },
  { label: 'Industrial', value: 'industrial' },
] as const

export type JobTypeFilter = (typeof JOB_TYPE_FILTERS)[number]['value']

/** Mirrors Job::TYPES. */
export const JOB_TYPE_OPTIONS = [
  { label: 'Residential', value: 'residential' },
  { label: 'Commercial', value: 'commercial' },
  { label: 'Industrial', value: 'industrial' },
] as const

/**
 * A project carries the same type taxonomy as the job raised from it — declared
 * here rather than duplicated, so the two can never drift.
 */
export const PROJECT_TYPE_OPTIONS: readonly SelectOption[] = JOB_TYPE_OPTIONS

export type JobStatusFilter = (typeof JOB_STATUS_FILTERS)[number]['value']

/** Options for the Create Job dialog's status field. */
export const JOB_STATUS_OPTIONS: readonly SelectOption[] = JOB_STATUS_FILTERS.filter(
  (option) => option.value !== 'all',
).map((option) => ({ label: option.label, value: option.value }))

export const JOB_STATUSES: readonly JobStatus[] = JOB_STATUS_OPTIONS.map(
  (option) => option.value as JobStatus,
)

/* -------------------------------------------------------------------------- */
/*  Estimates                                                                 */
/* -------------------------------------------------------------------------- */

/** Mirrors Estimate::STATUSES, plus the "all" pseudo-filter. */
export const ESTIMATE_STATUS_FILTERS = [
  { label: 'All Statuses', value: 'all' },
  { label: 'Draft', value: 'draft' },
  { label: 'Sent', value: 'sent' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
] as const

export type EstimateStatusFilter = (typeof ESTIMATE_STATUS_FILTERS)[number]['value']

/** Options for the Create Estimate dialog's status field. */
export const ESTIMATE_STATUS_OPTIONS: readonly SelectOption[] =
  ESTIMATE_STATUS_FILTERS.filter((option) => option.value !== 'all').map((option) => ({
    label: option.label,
    value: option.value,
  }))

export const ESTIMATE_STATUSES: readonly EstimateStatus[] =
  ESTIMATE_STATUS_OPTIONS.map((option) => option.value as EstimateStatus)

/** Mirrors Estimate::SORTS. */
export const ESTIMATE_SORT_OPTIONS = [
  { label: 'Date (Newest)', value: 'date-desc' },
  { label: 'Date (Oldest)', value: 'date-asc' },
  { label: 'Amount (Highest)', value: 'amount-desc' },
  { label: 'Amount (Lowest)', value: 'amount-asc' },
  { label: 'Estimate # (A–Z)', value: 'number-asc' },
] as const

export type EstimateSort = (typeof ESTIMATE_SORT_OPTIONS)[number]['value']

/* -------------------------------------------------------------------------- */
/*  Invoices                                                                  */
/* -------------------------------------------------------------------------- */

/** Mirrors Invoice::DISPLAY_STATUSES, plus the "all" pseudo-filter. */
export const INVOICE_STATUS_FILTERS = [
  { label: 'All Statuses', value: 'all' },
  { label: 'Draft', value: 'draft' },
  { label: 'Pending', value: 'sent' },
  { label: 'Paid', value: 'paid' },
  { label: 'Overdue', value: 'overdue' },
] as const

export type InvoiceStatusFilter = (typeof INVOICE_STATUS_FILTERS)[number]['value']

/** Mirrors Invoice::SORTS. */
export const INVOICE_SORT_OPTIONS = [
  { label: 'Date (Newest)', value: 'date-desc' },
  { label: 'Date (Oldest)', value: 'date-asc' },
  { label: 'Amount (Highest)', value: 'amount-desc' },
  { label: 'Amount (Lowest)', value: 'amount-asc' },
  { label: 'Invoice # (A–Z)', value: 'number-asc' },
] as const

export type InvoiceSort = (typeof INVOICE_SORT_OPTIONS)[number]['value']
