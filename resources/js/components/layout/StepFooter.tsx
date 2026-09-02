import { ArrowLeft, ArrowRight } from 'lucide-react'
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
  /**
   * A fixed destination to go back to. Omit both this and `backLabel` and the
   * footer offers plain "Back", which returns to whatever screen the person
   * actually came from — usually the truer answer, since a screen in this flow
   * is reached from more than one place.
   */
  backHref?: string
  backLabel?: string
  /** Draws the history-based "Back" when there is no fixed destination. */
  showBack?: boolean
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
 * One primary button, bottom right, named after the step it opens. Going back is a
 * ghost link on the left so the two never read as a choice between equals.
 */
export function StepFooter({
  current,
  href,
  onContinue,
  blockedReason,
  continueLabel,
  backHref,
  backLabel = 'Back',
  showBack = false,
  isBusy = false,
  className,
}: StepFooterProps) {
  const index = WORKFLOW_STEPS.findIndex((step) => step.key === current)
  const next = WORKFLOW_STEPS[index + 1]

  const label = continueLabel ?? `Continue to ${next?.label}`
  const isBlocked = Boolean(blockedReason)

  /*
   * A blocked step still draws its button, greyed, with the reason beside it —
   * that is the whole point of `blockedReason`. Without one of these there is
   * simply nowhere to go on to, and the footer carries only the way back.
   */
  const canContinue = Boolean(href || onContinue || isBlocked)

  // Nothing to offer in either direction.
  if (!canContinue && !backHref && !showBack) return null
  if (canContinue && !next && !continueLabel) return null

  return (
    <div
      className={cn(
        'mt-10 flex flex-col gap-4 border-t border-hairline pt-6',
        'sm:flex-row sm:items-center sm:justify-between',
        className,
      )}
    >
      <div className="min-w-0">
        {backHref ? (
          <ButtonLink href={backHref} variant="ghost" leftIcon={ArrowLeft}>
            {backLabel}
          </ButtonLink>
        ) : showBack ? (
          /*
           * The browser's own history, not a route: this screen is reached from
           * the review, from the estimates list and from a job, and "back"
           * should mean the one they came from rather than a guess.
           */
          <Button
            type="button"
            variant="ghost"
            leftIcon={ArrowLeft}
            onClick={() => window.history.back()}
          >
            Back
          </Button>
        ) : (
          <span />
        )}
      </div>

      {canContinue && (
        <div className="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:gap-4">
          {isBlocked && (
            <p className="text-md text-white/90 sm:text-right">{blockedReason}</p>
          )}

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
      )}
    </div>
  )
}
