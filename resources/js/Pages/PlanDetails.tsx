import { Head, usePage } from '@inertiajs/react'
import { ArrowLeft, Check, CreditCard, Layers, Receipt, Users } from 'lucide-react'
import { Alert, Button, ButtonLink, StatusChip } from '@/components/common'
import { BuySeatsModal, PlanPanel, PlanRow, UsageBar } from '@/components/payments'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import { useDisclosure } from '@/hooks'
import type { SeatOffer, SharedPageProps, SubscriptionPayment, SubscriptionSummary } from '@/types'
import { cn, formatCalendarDate, formatCents, quotePlan } from '@/utils'

export interface PlanDetailsProps {
  subscription: SubscriptionSummary
  seatOffer: SeatOffer
  /** The card on the subscription and what it is charged; null until it was paid through Stripe. */
  payment: SubscriptionPayment | null
  receipt: { readonly confirmationNumber: string | null; readonly url: string | null }
}

/**
 * Plan Details — everything about the plan the company is on, on one screen: what it costs, what it
 * includes and allows, how much of that is used, who holds the seats, and how it is paid.
 */
export default function PlanDetails({ subscription, seatOffer, payment, receipt }: PlanDetailsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const buySeats = useDisclosure()

  const { plan, seats, billingCycle } = subscription
  const yearly = billingCycle === 'yearly'
  const active = subscription.status === 'active'
  // A plan that is no longer sold: its features are shown as the nearest current plan's, but no price of
  // ours is shown as if it were what is billed.
  const retired = !seatOffer.priceKnown
  const quote = quotePlan(plan, billingCycle, subscription.annualDiscountPercent, seats.limit)
  const free = Math.max(0, seats.limit - seats.used)
  const seatShare = seats.limit > 0 ? Math.min(100, (seats.used / seats.limit) * 100) : 100

  return (
    <PageTransition>
      <Head title="Plan Details" />

      <PageHeader
        title="Plan Details"
        subtitle="The plan your company is on, what it includes, and how much of it is used."
        breadcrumbs={[{ label: 'Settings', href: ROUTES.settings }, { label: 'Plan Details' }]}
        actions={
          <>
            <ButtonLink href={`${ROUTES.settings}?tab=subscription`} variant="secondary" leftIcon={ArrowLeft}>
              Back
            </ButtonLink>
            {seatOffer.canBuy && (
              <Button variant="secondary" leftIcon={Users} onClick={buySeats.open}>
                Buy seats
              </Button>
            )}
            <ButtonLink href={ROUTES.changePlan} leftIcon={Layers}>
              Change plan
            </ButtonLink>
          </>
        }
      />

      {flash.success && (
        <Alert key={flash.success} tone="success" className="mb-5">
          {flash.success}
        </Alert>
      )}
      {retired && (
        <Alert tone="info" className="mb-5" title="This plan is no longer sold">
          Your account was set up on a plan that is no longer offered. It is shown here with the features and limits of{' '}
          {plan.name}; what you are actually billed is on your receipt. Change plan to move onto a current one.
        </Alert>
      )}

      {/* ----------------------------------------------------------------- hero -- */}
      <section className="mb-5 rounded-card border border-brand/50 bg-linear-to-br from-brand/10 via-white/4 to-transparent p-5 shadow-glow sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <p className="text-sm text-white/85">Current plan</p>
            <h2 className="mt-1 text-3xl font-bold text-white">{plan.name}</h2>
            <p className="mt-1 text-white/80">{plan.tagline}</p>
          </div>
          <StatusChip pill tone={active ? 'success' : 'neutral'} label={active ? 'Active' : 'Canceled'} />
        </div>
        <p className="mt-3 max-w-2xl text-sm text-white/85">{plan.description}</p>

        {!retired && (
          <div className="mt-5 flex flex-wrap items-end gap-x-10 gap-y-3">
            <p className="text-white">
              <strong className="text-4xl font-bold tabular-nums">{formatCents(Math.round(plan.price * 100))}</strong>
              <span className="text-white/75"> / seat / month</span>
            </p>
            <p className="text-white/85">
              <strong className="text-xl text-white tabular-nums">{formatCents(quote.total)}</strong>
              <span className="text-white/70">
                {' '}
                per {yearly ? 'year' : 'month'} for {seats.limit} {seats.limit === 1 ? 'seat' : 'seats'}
              </span>
            </p>
          </div>
        )}
      </section>

      <div className="grid items-start gap-5 xl:grid-cols-2">
        <div className="min-w-0 space-y-5">
          {/* ------------------------------------------------------------- billing -- */}
          <PlanPanel title="Billing">
            <dl className="divide-y divide-hairline">
              <PlanRow label="Plan">{plan.name}</PlanRow>
              <PlanRow label="Billing cycle">{yearly ? 'Yearly' : 'Monthly'}</PlanRow>
              <PlanRow label="Renews on">{formatCalendarDate(subscription.renewsOn, 'MMM d, yyyy')}</PlanRow>
              {!retired && (
                <>
                  <PlanRow label="Price per seat">
                    {formatCents(Math.round(plan.price * 100))} / month
                    {yearly && (
                      <span className="text-white/60">
                        {' '}
                        · {formatCents(Math.round(quote.total / quote.seats))} / year
                      </span>
                    )}
                  </PlanRow>
                  <PlanRow label={yearly ? 'Yearly total' : 'Monthly total'}>
                    <strong>{formatCents(quote.total)}</strong>
                  </PlanRow>
                </>
              )}
              {payment && (
                <PlanRow label="Charged each period">
                  <strong>{formatCents(payment.amount)}</strong>
                  {retired && <span className="text-white/60"> (as set up)</span>}
                </PlanRow>
              )}
            </dl>
          </PlanPanel>

          {/* ------------------------------------------------------------ payment -- */}
          <PlanPanel title="Payment method and receipt">
            {payment ? (
              <div className="space-y-3 p-4 text-sm">
                <p className="flex items-center gap-3 text-white">
                  <CreditCard size={20} aria-hidden className="shrink-0 text-brand" />
                  <span>
                    <strong>{payment.card.brand}</strong> •••• {payment.card.lastFour}
                    <span className="text-white/65">
                      {' '}
                      · Expires {payment.card.expiry} · {payment.card.holder}
                    </span>
                  </span>
                </p>
                {payment.card.billingAddress && <p className="text-xs text-white/60">{payment.card.billingAddress}</p>}
                {(receipt.confirmationNumber || receipt.url) && (
                  <p className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-hairline pt-3 text-white/80">
                    <Receipt size={16} aria-hidden className="text-white/60" />
                    {receipt.confirmationNumber && <span>Confirmation {receipt.confirmationNumber}</span>}
                    {receipt.url && (
                      <a
                        href={receipt.url}
                        target="_blank"
                        rel="noreferrer"
                        className="font-semibold text-brand underline"
                      >
                        View receipt
                      </a>
                    )}
                  </p>
                )}
              </div>
            ) : (
              <p className="p-4 text-sm text-white/70">
                No card is on this subscription yet. It appears here once the plan has been paid for.
              </p>
            )}
          </PlanPanel>

          {/* ------------------------------------------------------------ included -- */}
          <PlanPanel title="What is included">
            <ul className="space-y-2.5 p-4">
              {plan.features.map((feature) => (
                <li key={feature} className="flex items-start gap-2.5 text-sm text-white/90">
                  <Check size={16} aria-hidden className="mt-0.5 shrink-0 text-status-success" />
                  {feature}
                </li>
              ))}
            </ul>
          </PlanPanel>
        </div>

        <div className="min-w-0 space-y-5">
          {/* --------------------------------------------------------------- seats -- */}
          <PlanPanel
            title="Seats"
            actions={
              seatOffer.canBuy ? (
                <Button size="sm" variant="secondary" leftIcon={Users} onClick={buySeats.open}>
                  Buy seats
                </Button>
              ) : undefined
            }
          >
            <div className="space-y-3 p-4">
              <p className="text-sm text-white">
                <strong className="text-2xl tabular-nums">{seats.used}</strong>
                <span className="text-white/70"> of {seats.limit} seats in use</span>
                <span className={cn('ml-3 text-sm', free === 0 ? 'text-status-warning' : 'text-white/70')}>
                  {free === 0 ? 'None free' : `${free} free`}
                </span>
              </p>
              <div className="h-2 overflow-hidden rounded-full bg-white/12" aria-hidden>
                <div
                  className={cn('h-full rounded-full', free === 0 ? 'bg-status-warning' : 'bg-brand')}
                  style={{ width: `${seatShare}%` }}
                />
              </div>
              {seats.byRole.length > 0 && (
                <ul className="divide-y divide-hairline rounded-panel border border-hairline text-sm">
                  {seats.byRole.map(({ role, count }) => (
                    <li key={role} className="flex justify-between gap-4 px-3 py-2">
                      <span className="text-white/80">{role}</span>
                      <span className="tabular-nums text-white">{count}</span>
                    </li>
                  ))}
                </ul>
              )}
              <p className="text-xs text-white/60">A deactivated member does not hold a seat.</p>
            </div>
          </PlanPanel>

          {/* --------------------------------------------------------------- usage -- */}
          <PlanPanel title="Limits and usage this cycle">
            <ul className="divide-y divide-hairline">
              {subscription.usage.map((item) => (
                <UsageBar key={item.key} label={item.label} used={item.used} limit={item.limit} unit={item.unit} />
              ))}
            </ul>
          </PlanPanel>
        </div>
      </div>

      <BuySeatsModal isOpen={buySeats.isOpen} onClose={buySeats.close} offer={seatOffer} used={seats.used} />
    </PageTransition>
  )
}

PlanDetails.layout = appLayout
