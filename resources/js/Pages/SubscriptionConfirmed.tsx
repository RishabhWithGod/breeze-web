import { Head } from '@inertiajs/react'
import {
  ArrowRight,
  CalendarDays,
  Check,
  CreditCard,
  Crown,
  ExternalLink,
  FileText,
  ScrollText,
  UserRound,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { ButtonLink, StatusChip } from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import { cn, formatCalendarDate } from '@/utils'

export interface SubscriptionConfirmedProps {
  plan: { readonly name: string; readonly tagline: string; readonly status: 'active' | 'canceled' }
  confirmationNumber: string
  billingCycle: 'monthly' | 'yearly'
  /** `YYYY-MM-DD` */
  nextBillingDate: string
  administrator: { readonly name: string; readonly email: string }
  /** Stripe's own page for the receipt; null when Stripe has not produced one. */
  receiptUrl: string | null
}

/**
 * Subscription Confirmed — where a paid subscription lands.
 *
 * Every figure is what was recorded from the Stripe payment; the receipt link is
 * Stripe's own page, so it shows exactly what was charged.
 */
export default function SubscriptionConfirmed({
  plan,
  confirmationNumber,
  billingCycle,
  nextBillingDate,
  administrator,
  receiptUrl,
}: SubscriptionConfirmedProps) {
  return (
    <PageTransition>
      <Head title="Subscription Confirmed" />

      <header className="mb-6">
        <h1 className="text-3xl font-bold text-white">Subscription Confirmed</h1>
        <p className="mt-2 text-lg font-semibold text-white">Welcome to Breeze.Ai!</p>
        <p className="text-sm text-white/80">
          Your subscription is active and you&apos;re all set to start managing your takeoffs, estimates, and projects.
        </p>
      </header>

      <section className="mx-auto max-w-3xl rounded-card border border-brand/50 glass p-6 shadow-glow sm:p-8">
        <div className="flex flex-col items-center text-center">
          <span className="grid size-24 place-items-center rounded-full border-2 border-status-success bg-status-success/10 shadow-[0_0_28px_rgba(74,222,128,0.35)]">
            <span className="grid size-14 place-items-center rounded-full border-2 border-status-success text-status-success">
              <Check size={30} strokeWidth={2.5} aria-hidden />
            </span>
          </span>
          <h2 className="mt-5 text-3xl font-bold text-white">Subscription Confirmed!</h2>
          <p className="mt-2 text-md text-white/85">Your plan is now active and ready to use.</p>
        </div>

        <dl className="mt-6 space-y-3">
          <Row icon={Crown} tone="cyan" label="Plan">
            <div className="flex flex-wrap items-center gap-3">
              <strong className="text-lg text-white">{plan.name}</strong>
              {plan.status === 'active' && <StatusChip pill tone="success" label="Active" />}
            </div>
            <p className="mt-1 text-xs text-white/70">{plan.tagline}</p>
          </Row>

          <Row icon={ScrollText} tone="purple" label="Confirmation Number">
            <strong className="text-md tracking-wide text-white">{confirmationNumber}</strong>
          </Row>

          <Row icon={CreditCard} tone="blue" label="Billing Cycle">
            <strong className="text-md text-white">{billingCycle === 'yearly' ? 'Annual' : 'Monthly'}</strong>
          </Row>

          <Row icon={CalendarDays} tone="teal" label="Next Billing Date">
            <strong className="text-md text-white">{formatCalendarDate(nextBillingDate, 'MM/dd/yyyy')}</strong>
          </Row>

          <Row icon={UserRound} tone="orange" label="Company Administrator">
            <strong className="text-md text-white">{administrator.name}</strong>
            <p className="text-xs text-white/70">{administrator.email}</p>
          </Row>

          <Row icon={FileText} tone="purple" label="Receipt">
            {receiptUrl ? (
              <a
                href={receiptUrl}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-2 text-md font-semibold text-brand underline underline-offset-2"
              >
                View Receipt
                <ExternalLink size={16} aria-hidden />
              </a>
            ) : (
              <span className="text-sm text-white/70">Stripe emails your receipt after the payment clears.</span>
            )}
          </Row>
        </dl>

        <ButtonLink href={ROUTES.home} variant="blue" size="lg" fullWidth rightIcon={ArrowRight} className="mt-6">
          Continue to Dashboard
        </ButtonLink>
        <p className="mt-3 text-center text-xs text-white/70">
          Your company is set up. Add your team from Teams whenever you&apos;re ready.
        </p>
      </section>
    </PageTransition>
  )
}

SubscriptionConfirmed.layout = appLayout

const TONES = {
  cyan: 'border-brand bg-brand/15 text-brand',
  purple: 'border-purple-400 bg-purple-500/20 text-purple-300',
  blue: 'border-blue-400 bg-blue-500/20 text-blue-300',
  teal: 'border-teal-400 bg-teal-500/20 text-teal-300',
  orange: 'border-orange-400 bg-orange-500/20 text-orange-300',
} as const

/** One fact about the subscription: an icon and what it is on the left, its value on the right. */
function Row({
  icon: Icon,
  tone,
  label,
  children,
}: {
  icon: LucideIcon
  tone: keyof typeof TONES
  label: string
  children: React.ReactNode
}) {
  return (
    <div className="grid items-center gap-3 rounded-panel border border-hairline bg-white/4 px-4 py-3 sm:grid-cols-[minmax(0,16rem)_1fr]">
      <dt className="flex items-center gap-4 text-md text-white/90">
        <span className={cn('grid size-11 shrink-0 place-items-center rounded-full border', TONES[tone])}>
          <Icon size={20} aria-hidden />
        </span>
        {label}
      </dt>
      <dd className="min-w-0">{children}</dd>
    </div>
  )
}
