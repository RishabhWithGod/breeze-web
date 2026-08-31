import { Check } from 'lucide-react'
import { Card } from '@/components/common/Card'
import { WORKFLOW_STAGES, type WorkflowStage } from '@/constants'
import { cn } from '@/utils'

export interface WorkflowProgressProps {
  /** The stage being worked on now. */
  current: WorkflowStage
  /**
   * Stages already finished. Passed explicitly rather than inferred from `current`,
   * because a takeoff can reach the Job stage with its estimate raised or not — the
   * page knows which records exist and the bar should say so.
   */
  done?: readonly WorkflowStage[]
  className?: string
}

/**
 * Where this takeoff is in the workflow.
 *
 * Three states, distinguishable without colour: a tick for finished, a filled dot
 * for the stage in hand, a hollow ring for the ones not started. That is the whole
 * question a first-time user has on this screen — what has happened, and what is
 * happening now.
 */
export function WorkflowProgress({ current, done = [], className }: WorkflowProgressProps) {
  const currentIndex = WORKFLOW_STAGES.findIndex((stage) => stage.key === current)

  const stateOf = (stage: WorkflowStage, index: number) => {
    if (done.includes(stage)) return 'done'
    if (index === currentIndex) return 'current'
    // Anything before the current stage counts as done even if it was not listed.
    return index < currentIndex ? 'done' : 'todo'
  }

  const finished = WORKFLOW_STAGES.filter(
    (stage, index) => stateOf(stage.key, index) === 'done',
  ).length

  return (
    <Card padding="md" className={className}>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <h2 className="text-lg font-semibold text-white">Workflow Progress</h2>
        <p className="text-md text-white/75">
          {finished} of {WORKFLOW_STAGES.length} complete
        </p>
      </div>

      <ol className="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-0">
        {WORKFLOW_STAGES.map((stage, index) => {
          const state = stateOf(stage.key, index)

          return (
            <li
              key={stage.key}
              className="flex items-center gap-3 sm:flex-1 sm:flex-col sm:gap-2 sm:text-center"
            >
              <span className="flex items-center gap-3 sm:w-full sm:gap-0">
                {/* Rail to the left, drawn only between markers. */}
                <span
                  aria-hidden
                  className={cn(
                    'hidden h-px flex-1 sm:block',
                    index === 0 && 'invisible',
                    state === 'todo' ? 'bg-hairline' : 'bg-status-success/50',
                  )}
                />

                <span
                  aria-hidden
                  className={cn(
                    'grid size-7 shrink-0 place-items-center rounded-full text-2xs font-semibold',
                    state === 'done' && 'bg-status-success text-brand-ink',
                    state === 'current' && 'bg-brand text-brand-ink ring-4 ring-brand/25',
                    state === 'todo' && 'border border-hairline-strong text-white/60',
                  )}
                >
                  {state === 'done' ? (
                    <Check size={14} strokeWidth={3} />
                  ) : state === 'current' ? (
                    <span className="size-2 rounded-full bg-brand-ink" />
                  ) : (
                    index + 1
                  )}
                </span>

                <span
                  aria-hidden
                  className={cn(
                    'hidden h-px flex-1 sm:block',
                    index === WORKFLOW_STAGES.length - 1 && 'invisible',
                    index < currentIndex ? 'bg-status-success/50' : 'bg-hairline',
                  )}
                />
              </span>

              <span
                className={cn(
                  'text-md whitespace-nowrap',
                  state === 'current' && 'font-semibold text-white',
                  state === 'done' && 'text-white/90',
                  state === 'todo' && 'text-white/60',
                )}
              >
                {stage.label}
                <span className="sr-only">
                  {state === 'done'
                    ? ' — complete'
                    : state === 'current'
                      ? ' — in progress'
                      : ' — not started'}
                </span>
              </span>
            </li>
          )
        })}
      </ol>
    </Card>
  )
}
