import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Check } from 'lucide-react'
import { Button, Modal } from '@/components/common'
import { routeTo } from '@/constants'
import type { SubscriptionSummary } from '@/types'
import { cn, formatCents, quotePlan } from '@/utils'

export interface ChangePlanModalProps {
  isOpen: boolean
  onClose: () => void
  subscription: SubscriptionSummary
}

/**
 * Choosing a plan and how often it renews. Nothing is charged here; the server
 * refuses a plan the company has already outgrown and says why, and that refusal
 * is shown in the dialog.
 */
export function ChangePlanModal({ isOpen, onClose, subscription }: ChangePlanModalProps) {
  return isOpen ? (
    <PlanForm key="open" subscription={subscription} onClose={onClose} />
  ) : (
    <Modal isOpen={false} onClose={onClose} />
  )
}

function PlanForm({ subscription, onClose }: { subscription: SubscriptionSummary; onClose: () => void }) {
  const [planKey, setPlanKey] = useState(subscription.plan.key)
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>(subscription.billingCycle)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const plan = subscription.plans.find((option) => option.key === planKey)!
  const quote = quotePlan(plan, cycle, subscription.annualDiscountPercent)
  const unchanged = planKey === subscription.plan.key && cycle === subscription.billingCycle

  const save = () =>
    router.put(
      routeTo.subscriptionChange,
      { plan: planKey, billing_cycle: cycle },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: onClose,
        onError: (errors) => setError(errors['plan'] ?? errors['billing_cycle'] ?? 'That change was not accepted.'),
      },
    )

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Change plan"
      description="Pick a plan, and how often it renews. Nothing is charged from here."
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button isLoading={busy} disabled={unchanged} onClick={save}>
            {planKey !== subscription.plan.key ? `Switch to ${plan.name}` : 'Save'}
          </Button>
        </>
      }
    >
      <div
        role="group"
        aria-label="Billing cycle"
        className="mb-4 inline-flex overflow-hidden rounded-panel border border-hairline-strong"
      >
        {(['monthly', 'yearly'] as const).map((option) => (
          <button
            key={option}
            type="button"
            aria-pressed={cycle === option}
            onClick={() => setCycle(option)}
            className={cn(
              'px-5 py-2 text-md font-semibold transition-colors',
              cycle === option ? 'bg-blue-600 text-white' : 'bg-white/6 text-white/85 hover:bg-white/12',
            )}
          >
            {option === 'yearly' ? `Annual · save ${subscription.annualDiscountPercent}%` : 'Monthly'}
          </button>
        ))}
      </div>

      <ul className="grid gap-3 md:grid-cols-3">
        {subscription.plans.map((option) => {
          const selected = planKey === option.key

          return (
            <li key={option.key}>
              <button
                type="button"
                aria-pressed={selected}
                onClick={() => {
                  setPlanKey(option.key)
                  setError(null)
                }}
                className={cn(
                  'flex h-full w-full flex-col rounded-panel border p-4 text-left transition-colors',
                  selected
                    ? 'border-brand bg-brand/10 shadow-glow'
                    : 'border-hairline bg-white/4 hover:border-brand/50',
                )}
              >
                <span className="flex items-center justify-between gap-2">
                  <span className="text-lg font-bold text-white">{option.name}</span>
                  {option.key === subscription.plan.key && (
                    <span className="rounded-full border border-status-success/50 bg-status-success/12 px-2 py-0.5 text-2xs font-semibold text-status-success">
                      Current
                    </span>
                  )}
                </span>
                <span className="mt-1 text-sm text-white/75">{option.tagline}</span>
                <span className="mt-2 text-white">
                  <strong className="text-2xl tabular-nums">${option.price}</strong>
                  <span className="text-sm text-white/75"> /month</span>
                </span>
                <ul className="mt-3 space-y-1 text-sm text-white/90">
                  {[option.usersLabel, ...option.highlights].map((line) => (
                    <li key={line} className="flex items-start gap-2">
                      <Check size={14} aria-hidden className="mt-0.5 shrink-0 text-status-success" />
                      {line}
                    </li>
                  ))}
                </ul>
              </button>
            </li>
          )
        })}
      </ul>

      <p className="mt-4 flex items-baseline justify-between gap-3 rounded-panel border border-hairline bg-white/4 px-4 py-3 text-md text-white/90">
        <span>
          {plan.name} · {cycle === 'yearly' ? 'per year' : 'per month'}
        </span>
        <strong className="text-lg text-white tabular-nums">{formatCents(quote.total)}</strong>
      </p>

      {error && (
        <p
          role="alert"
          className="mt-4 rounded-panel border border-status-danger/50 bg-status-danger/12 px-4 py-3 text-sm text-red-200"
        >
          {error}
        </p>
      )}
    </Modal>
  )
}
