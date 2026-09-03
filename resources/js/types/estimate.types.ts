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
  /**
   * The client. Clients are projects, so this is a `projects` id — the
   * estimate's own `client`/`project` name columns are snapshots the server
   * writes from it, never typed here.
   */
  client_id: string
  upload_id: string
}
