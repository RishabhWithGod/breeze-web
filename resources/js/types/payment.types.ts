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
  /** Dollars per seat per month. */
  readonly price: number
  /** The seat count Payment Setup's picker opens on for this plan — a starting point, not a cap. */
  readonly maxUsers: number
  readonly usersLabel: string
  /** Shown beside the price for a plan priced per seat with no built-in ceiling — e.g. Platinum's "contact us for volume pricing". */
  readonly volumePricingNote?: string
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

/** One role's share of the seats in use — what "Invite Team Members" reads before adding another person. */
export interface SeatsByRole {
  readonly role: string
  readonly count: number
}

/** The company's plan, and how much of it is used — counted from real records. */
export interface SubscriptionSummary {
  readonly plan: SubscriptionPlan
  readonly status: 'active' | 'canceled'
  readonly billingCycle: 'monthly' | 'yearly'
  /** `YYYY-MM-DD` */
  readonly renewsOn: string
  /** People with an account, against the seats this company actually bought. */
  readonly seats: { readonly used: number; readonly limit: number; readonly byRole: readonly SeatsByRole[] }
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

/** What buying more seats costs and how it would be paid — the Buy Seats dialog reads this. */
export interface SeatOffer {
  readonly planName: string
  readonly cycle: 'monthly' | 'yearly'
  /** Cents, per seat, per billing cycle. */
  readonly perSeatCents: number
  /** False on a plan that is no longer sold: the real rate is Stripe's, so no price is shown. */
  readonly priceKnown: boolean
  readonly seats: number
  readonly renewsOn: string | null
  /** The part of the current period still to run, 0 to 1. */
  readonly periodShare: number
  /** `stripe` when the card on the subscription is charged; `local` outside production; null when seats cannot be added here. */
  readonly via: 'stripe' | 'local' | null
  readonly canBuy: boolean
  readonly maxAdd: number
}
