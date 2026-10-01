import { motion } from 'framer-motion'
import type { ProcessingStageState } from '@/types'
import { cn } from '@/utils'

export interface ProcessingStepListProps {
  stages: readonly ProcessingStageState[]
  className?: string
}

const DOT_STYLES: Record<ProcessingStageState['status'], string> = {
  complete: 'border-brand bg-brand',
  active: 'border-brand bg-brand shadow-glow',
  failed: 'border-status-danger bg-status-danger',
  pending: 'border-white/40 bg-transparent',
}

const STATUS_TEXT: Record<ProcessingStageState['status'], { label: string; className: string }> = {
  complete: { label: 'Completed', className: 'text-brand/80' },
  active: { label: 'In progress', className: 'text-brand' },
  failed: { label: 'Failed', className: 'text-red-300' },
  pending: { label: 'Pending', className: 'text-white/65' },
}

/** Vertical status list: a dot per stage joined by a line, state on the right. */
export function ProcessingStepList({ stages, className }: ProcessingStepListProps) {
  return (
    <ol className={cn('text-left', className)}>
      {stages.map((stage, index) => {
        const isLast = index === stages.length - 1
        const state = STATUS_TEXT[stage.status]

        return (
          <motion.li
            key={stage.id}
            initial={{ opacity: 0, x: -10 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, delay: index * 0.04 }}
            className="relative flex items-center gap-4 py-2.5"
          >
            {!isLast && (
              <span
                className={cn(
                  'absolute top-[calc(50%+11px)] -bottom-[calc(50%-11px)] left-[9px] w-px border-l border-dashed',
                  stage.status === 'complete' ? 'border-brand/60' : 'border-white/25',
                )}
                aria-hidden
              />
            )}

            <span
              className={cn(
                'relative z-1 size-5 shrink-0 rounded-full border-2 transition-colors duration-300',
                DOT_STYLES[stage.status],
              )}
              aria-hidden
            />

            <p
              className={cn(
                'min-w-0 flex-1 truncate text-md',
                stage.status === 'pending' ? 'text-white/80' : 'text-white',
              )}
            >
              {stage.label}
            </p>
            <span className={cn('shrink-0 text-sm', state.className)}>{state.label}</span>
          </motion.li>
        )
      })}
    </ol>
  )
}
