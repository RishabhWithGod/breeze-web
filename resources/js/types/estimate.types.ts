export type EstimateStatus = 'draft' | 'sent' | 'approved' | 'rejected'

export interface Estimate {
  readonly id: number
  /** Human reference, e.g. "EST-1082". */
  readonly number: string
  readonly client: string
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
  issued_on: string
  amount: string
  status: EstimateStatus
  /** Who the estimate is for. What narrows the project list. */
  client_id: string
  /**
   * And which of their projects it is on — the answer the server keeps. The
   * `client`/`project` name columns are snapshots written from the project's
   * own client, never typed here.
   */
  project_id: string
  upload_id: string
}
