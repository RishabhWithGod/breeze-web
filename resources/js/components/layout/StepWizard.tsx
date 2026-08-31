import { Link } from '@inertiajs/react'
import { ArrowRight, Check } from 'lucide-react'
import { WORKFLOW_STEPS, type WorkflowStep } from '@/constants'
import { cn } from '@/utils'

export interface StepWizardProps {
  current: WorkflowStep
  /**
   * Where each finished step goes back to. A step with no href is shown as done
   * but is not a link — the record it would open does not exist yet.
   */
  hrefs?: Partial<Record<WorkflowStep, string>>
  /** Overrides the "Next: …" line, for a step whose successor has a better name. */
  nextLabel?: string
  className?: string
}

/**
 * Where this drawing is, and what happens next.
 *
 * Answers the three questions a first-time user has on every screen: where am I
 * (step N of 6, highlighted), what happened (the ticked steps behind me), and what
 * is next (named explicitly rather than left to be inferred from a rail).
 *
 * Only completed steps link. A step ahead has nothing to open, and making it look
 * clickable would promise a shortcut that does not exist.
 */
export function StepWizard({ current, hrefs, nextLabel, className }: StepWizardProps) {
  const currentIndex = WORKFLOW_STEPS.findIndex((step) => step.key === current)
  const total = WORKFLOW_STEPS.length
  const stepNumber = currentIndex + 1
  const next = WORKFLOW_STEPS[currentIndex + 1]

  // Progress is measured by steps *finished*, so the first step does not open on a
  // bar that already claims credit for it.
  const donePct = Math.round((currentIndex / total) * 100)

  return (
    <nav aria-label="Takeoff progress" className={cn('mb-8', className)}>
      <div className="mb-3 flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <p className="text-md text-white/85">
          Step <span className="font-semibold text-white">{stepNumber}</span> of {total}
          <span className="mx-2 text-white/25">·</span>
          <span className="font-semibold text-white">
            {WORKFLOW_STEPS[currentIndex]?.label}
          </span>
        </p>

        {(nextLabel ?? next?.label) && (
          <p className="flex items-center gap-1.5 text-md text-white/75">
            Next
            <ArrowRight size={13} aria-hidden />
            <span className="text-white">{nextLabel ?? next?.label}</span>
          </p>
        )}
      </div>

      {/* One bar, so progress reads before any of the labels do. */}
      <div
        role="progressbar"
        aria-valuenow={donePct}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={`${donePct}% of the takeoff complete`}
        className="h-1.5 w-full overflow-hidden rounded-pill bg-white/10"
      >
        <div
          className="h-full rounded-pill bg-brand transition-[width] duration-500 ease-out"
          style={{ width: `${donePct}%` }}
        />
      </div>

      <ol className="mt-4 flex min-w-max items-center gap-2 overflow-x-auto pb-1 sm:min-w-0">
        {WORKFLOW_STEPS.map((step, index) => {
          const isDone = index < currentIndex
          const isCurrent = index === currentIndex
          const href = isDone ? hrefs?.[step.key] : undefined

          const body = (
            <span
              className={cn(
                'flex items-center gap-2.5 rounded-panel px-3 py-2 transition-colors',
                isCurrent && 'bg-brand/15',
                href && 'hover:bg-white/10',
              )}
            >
              <span
                aria-hidden
                className={cn(
                  'grid size-6 shrink-0 place-items-center rounded-full text-2xs font-semibold',
                  isDone && 'bg-status-success text-brand-ink',
                  isCurrent && 'bg-brand text-brand-ink',
                  !isDone && !isCurrent && 'border border-hairline-strong text-white/70',
                )}
              >
                {isDone ? <Check size={13} /> : index + 1}
              </span>
              <span
                className={cn(
                  'text-md whitespace-nowrap',
                  isCurrent && 'font-semibold text-white',
                  isDone && 'text-white/90',
                  !isDone && !isCurrent && 'text-white/65',
                )}
              >
                {step.label}
              </span>
            </span>
          )

          return (
            <li key={step.key} className="flex items-center gap-2">
              {href ? (
                <Link href={href} aria-label={`Back to ${step.label}`}>
                  {body}
                </Link>
              ) : (
                <span
                  aria-current={isCurrent ? 'step' : undefined}
                  aria-disabled={!isCurrent || undefined}
                  title={
                    isCurrent
                      ? undefined
                      : isDone
                        ? `${step.label} is complete`
                        : `${step.label} unlocks once the earlier steps are done`
                  }
                  className={cn(!isDone && !isCurrent && 'cursor-not-allowed')}
                >
                  {body}
                </span>
              )}

              {index < total - 1 && (
                <span
                  aria-hidden
                  className={cn(
                    'h-px w-6 shrink-0 sm:w-10',
                    index < currentIndex ? 'bg-status-success/50' : 'bg-hairline',
                  )}
                />
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
