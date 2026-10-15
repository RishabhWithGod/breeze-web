import type { SubscriptionPlan } from '@/types'

/** What a plan costs for a billing cycle, in whole cents. */
export interface PlanQuote {
  readonly months: number
  readonly seats: number
  readonly subtotal: number
  readonly annualDiscountPercent: number
  readonly annualDiscount: number
  readonly total: number
}

/**
 * The same sum the server does (`PlanPricing::quote`), so the total on screen is the
 * total that is recorded. The server works it out again before saving anything.
 * Per seat: `plan.price` is the per-seat rate, multiplied by how many are chosen.
 */
export function quotePlan(
  plan: SubscriptionPlan,
  cycle: 'monthly' | 'yearly',
  annualDiscountPercent: number,
  seats: number,
): PlanQuote {
  const months = cycle === 'yearly' ? 12 : 1
  const safeSeats = Math.max(1, Math.trunc(seats))
  const subtotal = Math.round(plan.price * 100) * months * safeSeats

  const annualPercent = cycle === 'yearly' ? annualDiscountPercent : 0
  const annualDiscount = Math.round((subtotal * annualPercent) / 100)

  return {
    months,
    seats: safeSeats,
    subtotal,
    annualDiscountPercent: annualPercent,
    annualDiscount,
    total: subtotal - annualDiscount,
  }
}

/** Cents as dollars: 143040 → "$1,430.40". */
export function formatCents(cents: number): string {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100)
}
