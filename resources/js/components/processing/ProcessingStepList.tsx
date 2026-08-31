import { motion } from 'framer-motion'
import { Check, Loader2, X } from 'lucide-react'
import type { ProcessingStageState } from '@/types'
import { cn } from '@/utils'

export interface ProcessingStepListProps {
  stages: readonly ProcessingStageState[]
  className?: string
}

const BUBBLE_STYLES: Record<ProcessingStageState['status'], string> = {
  complete: 'bg-brand-deep text-brand-ink',
  active: 'bg-navy-950 text-brand ring-2 ring-brand',
  failed: 'bg-status-danger text-white',
  pending: 'bg-status-neutral text-brand-ink',
}

/** Vertical checklist of pipeline stages with per-stage status indicators. */
export function ProcessingStepList({ stages, className }: ProcessingStepListProps) {
  return (
    <ol className={cn('relative space-y-1 text-left', className)}>
      {stages.map((stage, index) => {
        const isLast = index === stages.length - 1

        return (
          <motion.li
            key={stage.id}
            initial={{ opacity: 0, x: -10 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, delay: index * 0.04 }}
            className="relative flex gap-4 pb-5 last:pb-0"
          >
            {!isLast && (
              <span
                className={cn(
                  'absolute top-8 bottom-0 left-[13px] w-0.5 transition-colors duration-500',
                  stage.status === 'complete' ? 'bg-brand-deep' : 'bg-white/20',
                )}
                aria-hidden
              />
            )}

            <span
              className={cn(
                'relative z-1 grid size-7 shrink-0 place-items-center rounded-full text-sm font-semibold transition-colors duration-300',
                BUBBLE_STYLES[stage.status],
              )}
            >
              {stage.status === 'complete' ? (
                <Check size={15} strokeWidth={3} aria-hidden />
              ) : stage.status === 'failed' ? (
                <X size={15} strokeWidth={3} aria-hidden />
              ) : stage.status === 'active' ? (
                <Loader2 size={15} className="animate-spin" aria-hidden />
              ) : (
                index + 1
              )}
            </span>

            <div className="min-w-0 pt-0.5">
              <p
                className={cn(
                  'text-md font-medium transition-colors',
                  stage.status === 'pending' ? 'text-white/80' : 'text-white',
                )}
              >
                {stage.label}
              </p>
              <p className="mt-0.5 text-sm text-white/75">{stage.description}</p>
            </div>

            <span className="sr-only">{stage.status}</span>
          </motion.li>
        )
      })}
    </ol>
  )
}
