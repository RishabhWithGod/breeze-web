import { motion } from 'framer-motion'
import { Check, X } from 'lucide-react'
import type { ProcessingStageState } from '@/types'
import { cn } from '@/utils'

export interface ProcessingStepListProps {
  stages: readonly ProcessingStageState[]
  className?: string
}

const STATUS_TEXT: Record<ProcessingStageState['status'], string> = {
  complete: 'Completed',
  active: 'In progress',
  failed: 'Failed',
  pending: 'Pending',
}

/**
 * The pipeline as a checklist, for the dark analysis panel: a round marker per stage, the current one
 * outlined and lit, finished ones ticked, the rest quiet.
 */
export function ProcessingStepList({ stages, className }: ProcessingStepListProps) {
  return (
    <ol className={cn('grid gap-1.5 text-left', className)}>
      {stages.map((stage, index) => (
        <motion.li
          key={stage.id}
          initial={{ opacity: 0, x: -10 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.3, delay: index * 0.04 }}
          className={cn(
            'flex items-center gap-3 rounded-[10px] border px-3 py-2.5 transition-colors duration-300',
            stage.status === 'active' && 'border-[#3ee6ff]/60 bg-[rgb(62_230_255/0.08)]',
            stage.status === 'failed' && 'border-[#f87171]/60 bg-[rgb(248_113_113/0.1)]',
            (stage.status === 'complete' || stage.status === 'pending') && 'border-transparent',
          )}
        >
          <span
            aria-hidden
            className={cn(
              'grid size-[22px] shrink-0 place-items-center rounded-full border-[1.5px] transition-colors duration-300',
              stage.status === 'complete' && 'border-[#3ee6ff] bg-[#3ee6ff] text-[#06122e]',
              stage.status === 'active' && 'border-[#3ee6ff] text-[#3ee6ff] shadow-[0_0_10px_rgb(62_230_255/0.5)]',
              stage.status === 'failed' && 'border-[#f87171] bg-[#f87171] text-[#06122e]',
              stage.status === 'pending' && 'border-[rgb(126_170_255/0.35)]',
            )}
          >
            {stage.status === 'complete' && <Check size={13} strokeWidth={3} />}
            {stage.status === 'failed' && <X size={13} strokeWidth={3} />}
            {stage.status === 'active' && <span className="size-1.5 animate-pulse rounded-full bg-[#3ee6ff]" />}
          </span>

          <p
            className={cn(
              'min-w-0 flex-1 truncate text-[13.5px]',
              stage.status === 'pending' ? 'text-[#8196bf]' : 'text-white',
              stage.status === 'active' && 'font-medium',
            )}
          >
            {stage.label}
          </p>
          <span className="sr-only">{STATUS_TEXT[stage.status]}</span>
        </motion.li>
      ))}
    </ol>
  )
}
