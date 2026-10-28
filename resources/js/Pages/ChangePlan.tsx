import { useMemo, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { ArrowLeft, Check, Info, Layers, Lock, X } from 'lucide-react'
import { Alert, Button, ButtonLink } from '@/components/common'
import { PlanPanel } from '@/components/payments'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { SeatOffer, SharedPageProps, SubscriptionPlan, SubscriptionSummary } from '@/types'
import { cn, formatCalendarDate, formatCents, quotePlan } from '@/utils'

export interface ChangePlanProps {
  /** Where this was opened from; Back, Cancel and the result return there. */
  origin: 'settings' | 'plan'
  subscription: SubscriptionSummary
  seatOffer: SeatOffer
  /** For each plan, what stands in the way of moving onto it today (empty when nothing does). */
  blockers: Record<string, readonly string[]>
}

/**
 * Change Plan — the plans side by side, how often to be billed, and exactly what the move means before
 * it is made. A plan the company has outgrown stays on the screen with the reason it cannot be chosen.
 */
export default function ChangePlan({ origin, subscription, seatOffer, blockers }: ChangePlanProps) {
  const backUrl = origin === 'settings' ? `${ROUTES.settings}?tab=subscription` : ROUTES.planDetails
  const { errors } = usePage<SharedPageProps>().props
  const plans = subscription.plans
  const current = plans.find((option) => option.key === subscription.plan.key) ?? null
  const retired = current === null

  const [planKey, setPlanKey] = useState(current?.key ?? '')
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>(subscription.billingCycle)
  const [busy, setBusy] = useState(false)

  const chosen = plans.find((option) => option.key === planKey) ?? null
  const matrix = useMemo(() => featureMatrix(plans), [plans])
  const seats = subscription.seats.limit
  const discount = subscription.annualDiscountPercent

  const next = useMemo(
    () => (chosen ? quotePlan(chosen, cycle, discount, seats) : null),
    [chosen, cycle, discount, seats],
  )
  // What is billed now, when the plan is one we still price; a retired plan's real rate is Stripe's.
  const now = useMemo(
    () => (current ? quotePlan(current, subscription.billingCycle, discount, seats) : null),
    [current, subscription.billingCycle, discount, seats],
  )

  const unchanged =
    chosen === null || (current !== null && chosen.key === current.key && cycle === subscription.billingCycle)
  const blocked = chosen !== null && chosen.key !== current?.key ? (blockers[chosen.key] ?? []) : []
  const cannot = seatOffer.via === null
  const error = (errors as Record<string, string | undefined>)['plan']

  // Only a same-cycle move between plans we price can be estimated; anything else Stripe works out.
  const estimate =
    seatOffer.via === 'stripe' && now && next && cycle === subscription.billingCycle
      ? Math.round((next.total - now.total) * seatOffer.periodShare)
      : null

  const confirm = () => {
    if (chosen === null) return
    router.put(
      routeTo.subscriptionChange,
      { plan: chosen.key, billing_cycle: cycle, return_to: origin },
      { onStart: () => setBusy(true), onFinish: () => setBusy(false) },
    )
  }

  return (
    <PageTransition>
      <Head title="Change Plan" />

      <PageHeader
        title="Change Plan"
        subtitle="Compare the plans, pick one, and see what it means before you confirm."
        breadcrumbs={
          origin === 'settings'
            ? [{ label: 'Settings', href: backUrl }, { label: 'Change Plan' }]
            : [
                { label: 'Settings', href: ROUTES.settings },
                { label: 'Plan Details', href: ROUTES.planDetails },
                { label: 'Change Plan' },
              ]
        }
        actions={
          <ButtonLink href={backUrl} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      {retired && (
        <Alert tone="info" className="mb-4" title="Your plan is no longer sold">
          Your account is on {subscription.plan.name}, which is no longer offered. Pick one of the plans below to move
          onto.
        </Alert>
      )}
      {cannot && (
        <Alert tone="warning" className="mb-4">
          The plan cannot be changed from here. Please contact support.
        </Alert>
      )}
      {error && (
        <Alert tone="danger" className="mb-4">
          {error}
        </Alert>
      )}

      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="min-w-0 space-y-4">
          {/* ------------------------------------------------------------ billing cycle -- */}
          <PlanPanel title="Billing cycle">
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
                    aria-pressed={cycle === value}
                    onClick={() => setCycle(value)}
                    className={cn(
                      'min-w-28 px-5 py-2 text-sm font-semibold transition-colors',
                      cycle === value ? 'bg-blue-500 text-white' : 'bg-white/6 text-white/85 hover:bg-white/12',
                    )}
                  >
                    {label}
                  </button>
                ))}
              </div>
              {discount > 0 && (
                <span className="rounded-full border border-status-success/60 bg-status-success/15 px-3 py-1 text-xs font-semibold text-status-success">
                  Annual saves {discount}%
                </span>
              )}
            </div>
          </PlanPanel>

          {/* ----------------------------------------------------------------- plans -- */}
          <PlanPanel title="Choose a plan">
            <fieldset className="grid gap-3 p-4 lg:grid-cols-3">
              <legend className="sr-only">Choose a plan</legend>
              {plans.map((option) => (
                <PlanChoice
                  key={option.key}
                  plan={option}
                  cycle={cycle}
                  discount={discount}
                  selected={option.key === planKey}
                  isCurrent={option.key === current?.key}
                  reasons={option.key === current?.key ? [] : (blockers[option.key] ?? [])}
                  onSelect={() => setPlanKey(option.key)}
                />
              ))}
            </fieldset>
          </PlanPanel>

          {/* --------------------------------------------------------------- compare -- */}
          <PlanPanel title="Compare plans">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[34rem] text-left text-sm">
                <thead>
                  <tr className="border-b border-hairline text-xs text-white/70">
                    <th className="px-4 py-3 font-semibold">&nbsp;</th>
                    {plans.map((option) => (
                      <th
                        key={option.key}
                        className={cn('px-4 py-3 font-semibold', option.key === planKey ? 'text-brand' : 'text-white')}
                      >
                        {option.name}
                        {option.key === current?.key && (
                          <span className="ml-1.5 text-2xs text-status-success">(current)</span>
                        )}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-hairline">
                  <CompareRow
                    label="Price per seat / month"
                    values={plans.map((p) => formatCents(Math.round(p.price * 100)))}
                  />
                  <CompareRow label="AI takeoffs / month" values={plans.map((p) => String(p.limits.ai_takeoffs))} />
                  <CompareRow label="Estimates" values={plans.map((p) => String(p.limits.estimates))} />
                  <CompareRow label="Projects" values={plans.map((p) => String(p.limits.projects))} />
                  <CompareRow label="Drawing storage" values={plans.map((p) => `${p.limits.storage_gb} GB`)} />
                  {matrix.rows.map((feature) => (
                    <tr key={feature}>
                      <td className="px-4 py-2.5 text-white/85">{feature}</td>
                      {plans.map((option) => (
                        <td key={option.key} className="px-4 py-2.5">
                          {matrix.has(option.key, feature) ? (
                            <Check size={16} aria-label="Included" className="text-status-success" />
                          ) : (
                            <X size={16} aria-label="Not included" className="text-white/30" />
                          )}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </PlanPanel>
        </div>

        {/* ------------------------------------------------------------------- summary -- */}
        <aside className="min-w-0 space-y-4 xl:sticky xl:top-24">
          <PlanPanel title="What changes">
            <div className="p-4">
              <dl className="space-y-3 text-sm">
                <Side
                  label="Now"
                  plan={subscription.plan.name}
                  detail={`${subscription.billingCycle === 'yearly' ? 'Annual' : 'Monthly'} · ${seats} ${seats === 1 ? 'seat' : 'seats'}`}
                  total={now ? formatCents(now.total) : null}
                  per={subscription.billingCycle === 'yearly' ? 'year' : 'month'}
                />
                {chosen && next ? (
                  <Side
                    label="After"
                    highlight
                    plan={chosen.name}
                    detail={`${cycle === 'yearly' ? 'Annual' : 'Monthly'} · ${seats} ${seats === 1 ? 'seat' : 'seats'}`}
                    total={formatCents(next.total)}
                    per={cycle === 'yearly' ? 'year' : 'month'}
                  />
                ) : (
                  <p className="rounded-panel border border-dashed border-hairline-strong px-3 py-4 text-center text-xs text-white/60">
                    Pick a plan to see what it would cost.
                  </p>
                )}
              </dl>

              {chosen && !unchanged && (
                <div className="mt-4 space-y-3 border-t border-hairline pt-4">
                  {estimate !== null && (
                    <p className="flex items-baseline justify-between gap-3 text-sm text-white/90">
                      <span>{estimate >= 0 ? 'About, charged today' : 'About, credited'}</span>
                      <strong className="text-lg text-white tabular-nums">{formatCents(Math.abs(estimate))}</strong>
                    </p>
                  )}
                  <p className="flex gap-3 rounded-panel border border-hairline bg-white/4 p-3 text-xs text-white/85">
                    <Info size={16} aria-hidden className="shrink-0 text-brand" />
                    {seatOffer.via === 'stripe'
                      ? `The difference for the rest of this period (to ${seatOffer.renewsOn ? formatCalendarDate(seatOffer.renewsOn, 'MMM d') : 'renewal'}) is charged to your card on file, or credited if the new plan costs less. If the payment fails, the plan does not change.`
                      : seatOffer.via === 'local'
                        ? 'Test mode: the plan changes without a charge.'
                        : 'This account cannot be changed from here.'}
                  </p>
                </div>
              )}

              {blocked.length > 0 && (
                <Alert tone="warning" className="mt-4">
                  You can&apos;t move to {chosen?.name} yet: {blocked.join('; ')}.
                </Alert>
              )}

              <div className="mt-4 flex flex-col gap-2">
                <Button
                  variant="blue"
                  leftIcon={Layers}
                  isLoading={busy}
                  disabled={unchanged || cannot || blocked.length > 0}
                  onClick={confirm}
                >
                  {chosen !== null && chosen.key !== current?.key ? `Switch to ${chosen.name}` : 'Update billing cycle'}
                </Button>
                <ButtonLink href={backUrl} variant="secondary">
                  Cancel
                </ButtonLink>
              </div>

              {seatOffer.via === 'stripe' && (
                <p className="mt-3 flex items-center gap-2 text-xs text-white/60">
                  <Lock size={14} aria-hidden className="shrink-0 text-status-success" />
                  Payments are processed by Stripe.
                </p>
              )}
            </div>
          </PlanPanel>
        </aside>
      </div>
    </PageTransition>
  )
}

ChangePlan.layout = appLayout

function PlanChoice({
  plan,
  cycle,
  discount,
  selected,
  isCurrent,
  reasons,
  onSelect,
}: {
  plan: SubscriptionPlan
  cycle: 'monthly' | 'yearly'
  discount: number
  selected: boolean
  isCurrent: boolean
  reasons: readonly string[]
  onSelect: () => void
}) {
  const blocked = reasons.length > 0
  const perSeat = quotePlan(plan, cycle, discount, 1)

  return (
    <label
      className={cn(
        'relative flex flex-col rounded-card border p-4 transition-colors',
        blocked ? 'cursor-not-allowed opacity-70' : 'cursor-pointer',
        selected
          ? 'border-2 border-status-success bg-status-success/6 shadow-glow'
          : 'border-hairline-strong bg-white/4',
        !selected && !blocked && 'hover:border-brand/50',
      )}
    >
      <input
        type="radio"
        name="plan"
        value={plan.key}
        checked={selected}
        disabled={blocked}
        onChange={onSelect}
        className="sr-only"
      />

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

      {isCurrent ? (
        <span className="absolute -top-3 right-11 rounded-full border border-status-success bg-ocean-950 px-3 py-1 text-xs font-bold text-status-success">
          Current
        </span>
      ) : (
        plan.popular && (
          <span className="absolute -top-3 right-11 rounded-full bg-green-700 px-3 py-1 text-xs font-bold text-white ring-1 ring-white/40">
            Most Popular
          </span>
        )
      )}

      <span className="mt-3 flex items-baseline gap-1.5 text-white">
        <span className="text-3xl font-bold tabular-nums">{formatCents(Math.round(plan.price * 100))}</span>
        <span className="text-xs text-white/75">/seat/month</span>
      </span>
      {cycle === 'yearly' && (
        <span className="mt-0.5 text-xs text-white/65">{formatCents(perSeat.total)} / seat billed yearly</span>
      )}

      <ul className="mt-3 space-y-1.5">
        {plan.highlights.map((line) => (
          <li key={line} className="flex items-center gap-2 text-xs text-white/90">
            <Check size={14} aria-hidden className="shrink-0 text-status-success" />
            {line}
          </li>
        ))}
      </ul>

      <ul className="mt-3 space-y-0.5 border-t border-hairline pt-3 text-xs text-white/70">
        <li>{plan.limits.ai_takeoffs} AI takeoffs / month</li>
        <li>
          {plan.limits.estimates} estimates · {plan.limits.projects} projects
        </li>
        <li>{plan.limits.storage_gb} GB drawing storage</li>
      </ul>

      {blocked && (
        <p className="mt-3 rounded-panel border border-status-warning/40 bg-status-warning/10 p-2 text-xs text-status-warning">
          Not available yet: {reasons.join('; ')}.
        </p>
      )}
    </label>
  )
}

/**
 * Which features each plan has. A plan's own list says "Everything in Breeze Basic" and then only what
 * it adds, so a dearer plan has everything the cheaper ones list too — counted that way here, and the
 * "Everything in…" lines themselves are not rows.
 */
function featureMatrix(plans: readonly SubscriptionPlan[]) {
  const byPrice = [...plans].sort((a, b) => a.price - b.price)
  const own = (plan: SubscriptionPlan) => plan.features.filter((feature) => !feature.startsWith('Everything in'))
  const rows = [...new Set(byPrice.flatMap(own))]
  const held = new Map(
    byPrice.map((plan) => [plan.key, new Set(byPrice.filter((other) => other.price <= plan.price).flatMap(own))]),
  )

  return { rows, has: (key: string, feature: string) => held.get(key)?.has(feature) ?? false }
}

function CompareRow({ label, values }: { label: string; values: readonly string[] }) {
  return (
    <tr>
      <td className="px-4 py-2.5 text-white/85">{label}</td>
      {values.map((value, index) => (
        <td key={index} className="px-4 py-2.5 tabular-nums text-white">
          {value}
        </td>
      ))}
    </tr>
  )
}

function Side({
  label,
  plan,
  detail,
  total,
  per,
  highlight,
}: {
  label: string
  plan: string
  detail: string
  total: string | null
  per: string
  highlight?: boolean
}) {
  return (
    <div
      className={cn(
        'rounded-panel border p-3',
        highlight ? 'border-brand/50 bg-brand/8' : 'border-hairline bg-white/4',
      )}
    >
      <dt className="text-2xs uppercase tracking-wide text-white/60">{label}</dt>
      <dd className="mt-1">
        <strong className="block text-white">{plan}</strong>
        <span className="block text-xs text-white/70">{detail}</span>
        {total !== null && (
          <span className="mt-1 block text-white">
            <strong className="text-lg tabular-nums">{total}</strong>
            <span className="text-xs text-white/70"> / {per}</span>
          </span>
        )}
      </dd>
    </div>
  )
}
