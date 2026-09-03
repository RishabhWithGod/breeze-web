import { ArrowRight } from 'lucide-react'
import { Button, ButtonLink } from '@/components/common'
import { WORKFLOW_STEPS, type WorkflowStep } from '@/constants'
import { cn } from '@/utils'

export interface StepFooterProps {
  /** The step the page is on; the button is named after the one after it. */
  current: WorkflowStep
  /** Where the primary button goes. Omit and supply `onContinue` instead. */
  href?: string
  onContinue?: () => void
  /**
   * Blocks the step. The button is disabled and this sentence says why, so nobody
   * is left guessing what is missing.
   */
  blockedReason?: string
  /** Overrides the button text when the next step has a better name here. */
  continueLabel?: string
  isBusy?: boolean
  className?: string
}

/**
 * The way forward, in the same place on every screen.
 *
 * The wizard rail at the top says where you are; this says what to do about it.
 * Both are needed — a progress indicator on its own tells a first-time user their
 * position and nothing about their next move, which is exactly the complaint.
 *
 * One primary button, bottom right, named after the step it opens. Going back is
 * not here: it sits top-right in the page header on every screen, so the way out
 * is always in the same place rather than moving with the layout.
 */
export function StepFooter({
  current,
  href,
  onContinue,
  blockedReason,
  continueLabel,
  isBusy = false,
  className,
}: StepFooterProps) {
  const index = WORKFLOW_STEPS.findIndex((step) => step.key === current)
  const next = WORKFLOW_STEPS[index + 1]

  const label = continueLabel ?? `Continue to ${next?.label}`
  const isBlocked = Boolean(blockedReason)

  /*
   * A blocked step still draws its button, greyed, with the reason beside it —
   * that is the whole point of `blockedReason`.
   */
  if (! (href || onContinue || isBlocked)) return null
  if (!next && !continueLabel) return null

  return (
    <div
      className={cn(
        'mt-10 flex flex-col gap-4 border-t border-hairline pt-6',
        // Forward only, and to the right — back lives in the page header.
        'sm:flex-row sm:items-center sm:justify-end',
        className,
      )}
    >
      {isBlocked && <p className="text-md text-white/90 sm:text-right">{blockedReason}</p>}

      {href && !isBlocked ? (
        <ButtonLink href={href} size="lg" rightIcon={ArrowRight}>
          {label}
        </ButtonLink>
      ) : (
        <Button
          size="lg"
          rightIcon={ArrowRight}
          disabled={isBlocked}
          isLoading={isBusy}
          onClick={onContinue}
        >
          {label}
        </Button>
      )}
    </div>
  )
}
