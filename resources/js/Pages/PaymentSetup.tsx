import { Head, Link, useForm } from '@inertiajs/react'
import { ArrowLeft, ArrowRight, Check, Info, Lock } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Alert, Button } from '@/components/common'
import { buttonStyles } from '@/components/common/buttonStyles'
import { appLayout, PageTransition } from '@/components/layout'
import type { SubscriptionPlan } from '@/types'
import { cn, formatCents, quotePlan } from '@/utils'

export interface PaymentSetupProps {
  plans: readonly SubscriptionPlan[]
  annualDiscountPercent: number
  /** What this account chose before, when it comes back to this step. */
  saved: { readonly plan: string; readonly billingCycle: 'monthly' | 'yearly' } | null
  /** False while no Stripe key is set, so the screen can say so before anyone clicks. */
  stripeReady: boolean
  /** True when the person came back from Stripe without paying. */
  cancelled: boolean
}

/**
 * Payment Setup — the third setup step: which plan, then pay for it on Stripe.
 *
 * The card is entered on Stripe's own page, so it never touches this app and the
 * page it is typed on is always secure. The total shown is worked out by the same
 * sum the server does before it asks Stripe to charge anything.
 */
export default function PaymentSetup({
  plans,
  annualDiscountPercent,
  saved,
  stripeReady,
  cancelled,
}: PaymentSetupProps) {
  const initialPlan = plans.find((plan) => plan.key === saved?.plan) ?? plans.find((plan) => plan.popular) ?? plans[0]!
  const [planKey, setPlanKey] = useState(initialPlan.key)

  const { data, setData, post, transform, processing, errors } = useForm({
    billing_cycle: saved?.billingCycle ?? ('yearly' as 'monthly' | 'yearly'),
  })

  const plan = plans.find((option) => option.key === planKey)!
  const quote = useMemo(
    () => quotePlan(plan, data.billing_cycle, annualDiscountPercent),
    [plan, data.billing_cycle, annualDiscountPercent],
  )

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    transform((form) => ({ ...form, plan: planKey }))
    post('/payment-setup', { preserveScroll: true })
  }

  const planError = (errors as Record<string, string | undefined>)['plan']

  return (
    <PageTransition>
      <Head title="Payment Setup" />

      <header className="mb-4">
        <h1 className="text-3xl font-bold text-white">Payment Setup</h1>
        <p className="text-sm text-white/80">
          Choose your plan and enter your payment details to activate your subscription.
        </p>
      </header>

      {cancelled && (
        <Alert tone="warning" className="mb-4">
          Payment was not completed, so nothing was charged. You can try again below.
        </Alert>
      )}
      {planError && (
        <Alert tone="danger" className="mb-4">
          {planError}
        </Alert>
      )}
      {!stripeReady && (
        <Alert tone="warning" className="mb-4">
          Payments are not set up on this server yet, so a subscription cannot be started.
        </Alert>
      )}

      <form onSubmit={submit} noValidate className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="min-w-0 space-y-4">
          {/* ================================================= Select plan == */}
          <Panel title="Select Plan">
            <fieldset className="grid gap-3 p-4 lg:grid-cols-3">
              <legend className="sr-only">Select plan</legend>
              {plans.map((option) => (
                <PlanCard
                  key={option.key}
                  plan={option}
                  selected={option.key === planKey}
                  onSelect={() => setPlanKey(option.key)}
                />
              ))}
            </fieldset>
          </Panel>

          {/* ================================================ Billing cycle == */}
          <Panel title="Billing Cycle">
            <div className="flex flex-wrap items-center gap-3 p-4">
              <div
                role="group"
                aria-label="Billing cycle"
                className="inline-flex overflow-hidden rounded-panel border border-hairline-strong"
              >
                {(
                  [
                    ['monthly', 'Monthly'],
                    ['yearly', 'Annual'],
                  ] as const
                ).map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    aria-pressed={data.billing_cycle === value}
                    onClick={() => setData('billing_cycle', value)}
                    className={cn(
                      'min-w-28 px-5 py-2 text-sm font-semibold transition-colors',
                      data.billing_cycle === value
                        ? 'bg-blue-500 text-white'
                        : 'bg-white/6 text-white/85 hover:bg-white/12',
                    )}
                  >
                    {label}
                  </button>
                ))}
              </div>
              <SavePill>Save {annualDiscountPercent}%</SavePill>
            </div>
          </Panel>

          {/* ========================================================= Pay == */}
          <Panel title="Payment Information">
            <div className="space-y-3.5 p-4">
              <p className="text-sm text-white/90">
                You&apos;ll enter your card and billing address on Stripe&apos;s secure payment page, then come straight
                back here. Your first payment is taken when you pay.
              </p>

              <p className="flex items-center gap-3 rounded-panel border border-status-success/40 bg-status-success/10 px-3 py-2 text-xs text-white/90">
                <Lock size={16} aria-hidden className="shrink-0 text-status-success" />
                Payments are processed by Stripe. Your card details never touch our servers.
              </p>

              <div className="flex items-center justify-between gap-4">
                <Link href="/terms" className={buttonStyles({ variant: 'secondary', size: 'md' })}>
                  <ArrowLeft size={18} aria-hidden />
                  Back
                </Link>
                <Button
                  type="submit"
                  variant="blue"
                  size="md"
                  rightIcon={ArrowRight}
                  isLoading={processing}
                  disabled={!stripeReady}
                >
                  Activate Subscription
                </Button>
              </div>
            </div>
          </Panel>
        </div>

        {/* ================================================ Order summary == */}
        <aside className="min-w-0 space-y-4 xl:sticky xl:top-24">
          <Panel title="Order Summary">
            <div className="p-4">
              <dl className="space-y-3 text-sm">
                <SummaryRow label="Plan" value={<strong className="text-md text-white">{plan.name}</strong>} />
                <SummaryRow
                  label="Billing Cycle"
                  value={
                    <span className="flex items-center gap-2">
                      <strong className="text-white">{data.billing_cycle === 'yearly' ? 'Annual' : 'Monthly'}</strong>
                      {data.billing_cycle === 'yearly' && <SavePill>Save {annualDiscountPercent}%</SavePill>}
                    </span>
                  }
                />
                <SummaryRow label="Users Included" value={<strong className="text-white">{plan.usersLabel}</strong>} />
              </dl>

              <dl className="mt-4 space-y-2.5 border-t border-hairline pt-4 text-sm">
                <SummaryRow
                  label="Subtotal"
                  value={<strong className="text-white">{formatCents(quote.subtotal)}</strong>}
                />
                {quote.annualDiscount > 0 && (
                  <SummaryRow
                    tone="success"
                    label={`Annual Discount (${quote.annualDiscountPercent}%)`}
                    value={<strong>−{formatCents(quote.annualDiscount)}</strong>}
                  />
                )}
              </dl>

              <div className="mt-4 flex items-baseline justify-between border-t border-hairline pt-4">
                <span className="text-md font-semibold text-white">Total Due Today</span>
                <span className="text-2xl font-bold text-white tabular-nums" aria-live="polite">
                  {formatCents(quote.total)}
                </span>
              </div>

              <p className="mt-4 flex gap-3 rounded-panel border border-hairline bg-white/4 p-3 text-xs text-white/85">
                <Info size={16} aria-hidden className="shrink-0 text-brand" />
                {data.billing_cycle === 'yearly'
                  ? 'Your subscription renews annually at the then-current rate.'
                  : 'Your subscription renews monthly at the then-current rate.'}
              </p>

              <h3 className="mt-4 border-t border-hairline pt-4 text-md font-semibold text-white">
                Included in Your Plan
              </h3>
              <ul className="mt-2.5 space-y-2">
                {plan.features.map((feature) => (
                  <li key={feature} className="flex items-center gap-2.5 text-sm text-white/90">
                    <Check size={16} aria-hidden className="shrink-0 text-status-success" />
                    {feature}
                  </li>
                ))}
              </ul>
            </div>
          </Panel>
        </aside>
      </form>
    </PageTransition>
  )
}

PaymentSetup.layout = appLayout

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="overflow-hidden rounded-card border border-hairline glass">
      <header className="border-b border-hairline bg-linear-to-b from-ocean-700/50 to-ocean-700/10 px-4 py-3">
        <h2 className="text-lg font-semibold text-white">{title}</h2>
      </header>
      {children}
    </section>
  )
}

function SavePill({ children }: { children: React.ReactNode }) {
  return (
    <span className="rounded-full border border-status-success/60 bg-status-success/15 px-3 py-1 text-xs font-semibold text-status-success">
      {children}
    </span>
  )
}

function SummaryRow({ label, value, tone }: { label: string; value: React.ReactNode; tone?: 'success' }) {
  return (
    <div
      className={cn(
        'flex items-center justify-between gap-3',
        tone === 'success' ? 'text-status-success' : 'text-white/85',
      )}
    >
      <dt>{label}</dt>
      <dd className="text-right">{value}</dd>
    </div>
  )
}

/** One plan on offer: what it is and what it costs a month. */
function PlanCard({ plan, selected, onSelect }: { plan: SubscriptionPlan; selected: boolean; onSelect: () => void }) {
  return (
    <label
      className={cn(
        'relative flex cursor-pointer flex-col rounded-card border p-4 transition-colors',
        selected
          ? 'border-2 border-status-success bg-status-success/6 shadow-glow'
          : 'border-hairline-strong bg-white/4 hover:border-brand/50',
      )}
    >
      <input type="radio" name="plan" value={plan.key} checked={selected} onChange={onSelect} className="sr-only" />

      <span className="flex items-start justify-between gap-3">
        <span>
          <span className="block text-lg font-bold text-white">{plan.name}</span>
          <span className="block text-xs text-white/75">{plan.tagline}</span>
        </span>
        <span
          aria-hidden
          className={cn(
            'grid size-5 shrink-0 place-items-center rounded-full border-2',
            selected ? 'border-blue-400 bg-blue-500' : 'border-white/50',
          )}
        >
          {selected && <span className="size-1.5 rounded-full bg-white" />}
        </span>
      </span>

      {plan.popular && (
        <span className="absolute -top-2.5 right-11 rounded-full bg-status-success px-2.5 py-0.5 text-2xs font-bold text-ocean-950">
          Most Popular
        </span>
      )}

      <span className="mt-3 flex items-baseline gap-1.5 text-white">
        <span className="text-3xl font-bold tabular-nums">${plan.price}</span>
        <span className="text-xs text-white/75">/month</span>
      </span>

      <ul className="mt-3 space-y-1.5">
        {[plan.usersLabel, ...plan.highlights].map((line) => (
          <li key={line} className="flex items-center gap-2 text-xs text-white/90">
            <Check size={14} aria-hidden className="shrink-0 text-status-success" />
            {line}
          </li>
        ))}
      </ul>
    </label>
  )
}
