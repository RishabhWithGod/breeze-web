/**
 * Billing — invoices, their line items, and the workflow that moves one from
 * draft to paid. Mirrors `InvoiceResource`/`InvoiceItemResource`/
 * `InvoiceDetailController::present()`. Dates that identify a day are plain
 * `YYYY-MM-DD` strings; everything else is ISO, formatted client-side.
 */

export type InvoiceStatus = 'draft' | 'sent' | 'paid' | 'overdue'

/** The list row — one per invoice, as `Invoices.tsx` renders it. */
export interface Invoice {
  readonly id: number
  readonly invoiceNumber: string
  readonly client: string
  readonly jobId: number | null
  readonly jobName: string | null
  /** Whether its project is still open; null when it was not raised on one. */
  readonly projectOpen: boolean | null
  readonly date: string
  readonly dueDate: string | null
  readonly total: number
  readonly paidAmount: number
  readonly outstanding: number
  readonly status: InvoiceStatus
  readonly isEditable: boolean
}

/** The full detail screen's shape — everything the list row has, plus the rest of the header. */
export interface InvoiceDetail {
  readonly id: number
  readonly invoiceNumber: string
  readonly client: string
  /** The client's own id — clients are projects, so this is a `projects` id. */
  readonly clientId: number | null
  readonly jobId: number | null
  readonly jobName: string | null
  /** The project the job was raised on. */
  readonly projectName: string | null
  /** Where the client is billed — their primary site. */
  readonly billingAddress: string | null
  readonly estimateId: number | null
  readonly estimateNumber: string | null
  /** What the estimate it bills came to. */
  readonly estimateTotal: number | null
  readonly invoiceDate: string
  readonly dueDate: string | null
  readonly subtotal: number
  readonly taxPct: number
  readonly taxTotal: number
  readonly total: number
  readonly paidAmount: number
  readonly outstanding: number
  readonly status: InvoiceStatus
  readonly isEditable: boolean
  readonly notes: string | null
  readonly sentAt: string | null
  readonly paidAt: string | null
  readonly createdBy: string | null
  readonly createdAt: string
}

export interface InvoiceItemRow {
  readonly id: number
  readonly description: string
  /**
   * 'material'/'equipment'/'labor' when this line came from an estimate's
   * own line item (see `EstimateInvoiceSync`); null for a hand-typed line.
   */
  readonly sourceCategory: string | null
  /** Copied off the estimate, or typed in. */
  readonly source: 'estimate' | 'manual' | null
  readonly quantity: number
  readonly unitPrice: number
  readonly total: number
}

/** The bottom "Invoice Summary" card — a live aggregate, never a stored figure. */
export interface InvoiceSummary {
  readonly totalOutstanding: number
  readonly overdue: number
  readonly paidThisMonth: number
  readonly averageDaysToPay: number | null
}

/** A job offered in the Job select, for the create/edit forms. */
export interface InvoiceJobOption {
  readonly id: number
  readonly name: string
  readonly client?: string | null
  /** Its client's id, so picking a job can fill the Client select in. */
  readonly client_id?: number | null
  /** The project it was raised on. */
  readonly project_id?: number | null
  readonly project?: string | null
  /** Only a completed job can be invoiced. */
  readonly completed?: boolean
  /** What it really cost, for whoever may see costs; null otherwise. */
  readonly actual_cost?: number | null
}

/** What an invoice line is for. */
export type InvoiceLineCategory = 'labor' | 'material' | 'equipment' | 'other'

/** One line of an invoice not yet saved — what the Create screen builds and posts. */
export interface InvoiceDraftLine {
  readonly description: string
  readonly source_category: InvoiceLineCategory | null
  readonly source?: 'estimate' | 'manual'
  readonly quantity: number
  readonly unit_price: number
}

/** A convertible estimate offered on the Create Invoice screen. */
export interface InvoiceEstimateOption {
  readonly id: number
  readonly number: string
  readonly client: string
  /** Its client's id, so converting an estimate fills the Client select in. */
  readonly client_id: number | null
  readonly job_id: number | null
  /** The job it belongs to: its own, or — for an addendum — its original's. */
  readonly owner_job_id: number | null
  readonly project_id: number | null
  readonly kind: 'standalone' | 'addendum' | 'merged'
  readonly status: 'draft' | 'sent' | 'approved' | 'rejected'
  readonly addendum_number: number | null
  /** The original an addendum adds to. */
  readonly parent_number: string | null
  readonly grand_total: number
  /** Its lines, which become the invoice's starting lines. */
  readonly items: readonly InvoiceDraftLine[]
}

/** What the signed-in user may do — computed server-side, never guessed client-side. */
export interface InvoiceAbilities {
  readonly create: boolean
  readonly manage: boolean
}

/** Per-invoice actions, computed server-side on the detail screen. */
export interface InvoiceActionAbilities {
  readonly update: boolean
  readonly delete: boolean
  readonly send: boolean
  readonly markPaid: boolean
  /** Whether this signed-in user may edit the Journeyman Labor Hours card. */
  readonly manageJobCosts: boolean
}

/**
 * One person's total hours on a job, and whether that total is a manager's
 * own saved figure or just the raw sum of their time entries — see
 * `JobCostSummary::journeymanHours()`. No approval wait either way.
 */
export interface JourneymanHoursRow {
  readonly userId: number
  readonly name: string
  readonly role: string | null
  readonly rawHours: number
  readonly hours: number
  readonly isOverridden: boolean
}
