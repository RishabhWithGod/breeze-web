export type EstimateStatus = 'draft' | 'sent' | 'approved' | 'rejected'

export interface Estimate {
  readonly id: number
  /** Human reference, e.g. "EST-1082". */
  readonly number: string
  readonly client: string
  readonly project: string
  /** ISO timestamp — formatted with date-fns at render time. */
  readonly date: string
  readonly amount: number
  readonly status: EstimateStatus
}

/**
 * Payload posted by the Create Estimate dialog. Field names are snake_case
 * because they map straight onto StoreEstimateRequest's validation rules.
 * `number` is generated server-side.
 */
export interface EstimateDraft {
  client: string
  project: string
  issued_on: string
  amount: string
  status: EstimateStatus
}
