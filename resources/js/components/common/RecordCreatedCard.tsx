import type { ReactNode } from 'react'
import { CheckCircle2 } from 'lucide-react'
import { Card } from '@/components/common/Card'
import { cn } from '@/utils'

export interface RecordFact {
  label: string
  value: ReactNode
}

export interface RecordCreatedCardProps {
  /** "Estimate Created", "Job Created". */
  title: string
  /** One sentence naming what exists now. */
  description: ReactNode
  /** The figures worth reading without opening the record. */
  facts?: readonly RecordFact[]
  /**
   * The single next action. Rendered as the only filled button on the card, so it
   * never competes with the secondary set.
   */
  primary: ReactNode
  /** Open, download, and anything else — all visually quieter than `primary`. */
  secondary?: ReactNode
  /** What happens after the primary action, named. */
  nextStep?: string
  className?: string
}

/**
 * Confirms a record exists, and says what to do next.
 *
 * Replaces the pattern where a screen kept offering "Create estimate" whether or not
 * one had been created — the user's actual complaint. A record that exists is stated
 * as a fact, with its own identifiers, and the button becomes the *next* step rather
 * than the one already taken.
 *
 * Only `primary` is a filled button. Everything in `secondary` is deliberately
 * quieter, so there is never a choice between equals.
 */
export function RecordCreatedCard({
  title,
  description,
  facts = [],
  primary,
  secondary,
  nextStep,
  className,
}: RecordCreatedCardProps) {
  return (
    <Card padding="lg" className={cn('border-status-success/30', className)}>
      <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex min-w-0 gap-4">
          <span
            aria-hidden
            className="grid size-10 shrink-0 place-items-center rounded-full bg-status-success/15 text-status-success"
          >
            <CheckCircle2 size={22} />
          </span>

          <div className="min-w-0">
            <h3 className="text-xl font-semibold text-white">{title}</h3>
            <p className="mt-1 text-md text-white/85">{description}</p>

            {facts.length > 0 && (
              <dl className="mt-5 flex flex-wrap gap-x-10 gap-y-4">
                {facts.map((fact) => (
                  <div key={fact.label}>
                    <dt className="text-sm text-white/70">{fact.label}</dt>
                    <dd className="mt-0.5 text-lg font-semibold text-white">
                      {fact.value}
                    </dd>
                  </div>
                ))}
              </dl>
            )}
          </div>
        </div>

        <div className="flex shrink-0 flex-col gap-3 lg:items-end">
          {nextStep && (
            <p className="text-sm text-white/70 lg:text-right">
              Next step
              <span className="ml-1.5 font-semibold text-white">{nextStep}</span>
            </p>
          )}
          {primary}
          {secondary && (
            <div className="flex flex-wrap items-center gap-2 lg:justify-end">
              {secondary}
            </div>
          )}
        </div>
      </div>
    </Card>
  )
}
