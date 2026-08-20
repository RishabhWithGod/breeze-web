/**
 * Payment Settings — real processors, saved methods, billing defaults and
 * the transaction history behind them. Mirrors `PaymentSettingsController`
 * field-for-field; nothing here is computed twice on the client.
 */

export type PaymentProcessorStatus =
  | 'not_connected'
  | 'active'
  | 'limited'
  | 'error'
  | 'pending_setup'
  | 'inactive'

export interface PaymentProcessor {
  readonly id: number
  readonly key: 'stripe' | 'paypal' | 'square'
  readonly displayName: string
  readonly status: PaymentProcessorStatus
  readonly isConnected: boolean
  readonly connectedAt: string | null
  readonly lastTestedAt: string | null
  readonly lastError: string | null
  /** Field name → human label — drives the connect form for this processor. */
  readonly credentialFields: Record<string, string>
}

export interface PaymentMethod {
  readonly id: number
  readonly brand: string
  readonly lastFour: string
  readonly expiry: string
  readonly isDefault: boolean
  readonly isExpired: boolean
  readonly processorName: string
}

export interface BillingSettings {
  readonly autoSendInvoices: boolean
  readonly includePaymentInstructions: boolean
  readonly sendPaymentReminders: boolean
  readonly applyLateFeesAutomatically: boolean
  readonly defaultPaymentTerms: string
  readonly defaultCurrency: string
}

export interface PaymentTransactionRow {
  readonly id: number
  readonly date: string
  readonly description: string
  readonly amount: number
  readonly status: string
  readonly processorName: string
  readonly invoiceUrl: string | null
}

export interface PaymentTransactionsPage {
  readonly data: readonly PaymentTransactionRow[]
  readonly meta: {
    readonly current_page: number
    readonly last_page: number
    readonly total: number
  }
}
