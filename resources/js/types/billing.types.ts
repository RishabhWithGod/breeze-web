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
  readonly projectId: number | null
  readonly jobId: number | null
  readonly jobName: string | null
  readonly estimateId: number | null
  readonly estimateNumber: string | null
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
  readonly project_id?: number | null
}

/** A convertible estimate offered on the Create Invoice screen. */
export interface InvoiceEstimateOption {
  readonly id: number
  readonly number: string
  readonly client: string
  /** Its client's id, so converting an estimate fills the Client select in. */
  readonly project_id: number | null
  readonly job_id: number | null
  readonly grand_total: number
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
}
