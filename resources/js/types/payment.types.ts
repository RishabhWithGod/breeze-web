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

/** What a subscription plan allows. */
export interface PlanLimits {
  readonly ai_takeoffs: number
  readonly estimates: number
  readonly projects: number
  readonly storage_gb: number
}

export interface SubscriptionPlan {
  readonly key: string
  readonly name: string
  readonly tagline: string
  readonly description: string
  /** Dollars per month. */
  readonly price: number
  /** How many people can have an account on it; null when there is no limit. */
  readonly maxUsers: number | null
  readonly usersLabel: string
  readonly popular: boolean
  readonly limits: PlanLimits
  /** The few lines shown on the plan's card. */
  readonly highlights: readonly string[]
  readonly features: readonly string[]
}

export interface SubscriptionUsage {
  readonly key: string
  readonly label: string
  readonly used: number
  readonly limit: number
  readonly unit: string | null
}

/** The company's plan, and how much of it is used — counted from real records. */
export interface SubscriptionSummary {
  readonly plan: SubscriptionPlan
  readonly status: 'active' | 'canceled'
  readonly billingCycle: 'monthly' | 'yearly'
  /** `YYYY-MM-DD` */
  readonly renewsOn: string
  /** People with an account, against what the plan allows (null: no limit). */
  readonly seats: { readonly used: number; readonly limit: number | null }
  readonly annualDiscountPercent: number
  readonly usage: readonly SubscriptionUsage[]
  /** Every plan on offer, to choose between. */
  readonly plans: readonly SubscriptionPlan[]
}

/** What this account pays Breeze.Ai with, once it has paid through Stripe. */
export interface SubscriptionPayment {
  readonly planName: string
  readonly billingCycle: 'monthly' | 'yearly'
  /** Whole cents, per billing cycle. */
  readonly amount: number
  /** `YYYY-MM-DD` */
  readonly renewsOn: string
  readonly status: 'active' | 'canceled'
  readonly viaStripe: boolean
  readonly card: {
    readonly brand: string
    readonly lastFour: string
    readonly expiry: string
    readonly holder: string
    readonly billingAddress: string
  }
}

/** The company as it was described at setup. */
export interface CompanyDetails {
  readonly name: string
  readonly businessAddress: string
  readonly primaryContact: string
  readonly phone: string
  readonly email: string
  readonly licenseNumber: string | null
  readonly timezone: string
  readonly logoUrl: string | null
}
