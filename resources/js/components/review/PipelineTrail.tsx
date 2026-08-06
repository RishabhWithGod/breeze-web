import { ChevronRight } from 'lucide-react'
import { PIPELINE_STAGES } from '@/constants'
import type { DetectionPipeline } from '@/types'
import { cn } from '@/utils'

export interface PipelineTrailProps {
  pipeline: DetectionPipeline
  className?: string
}

/**
 * How far the AI carried this crop: generated → validated → classified → fusion
 * → final JSON. Stages the run did not reach are dimmed rather than hidden, so
 * every card reads against the same scale.
 */
export function PipelineTrail({ pipeline, className }: PipelineTrailProps) {
  return (
    <ul className={cn('flex flex-wrap items-center gap-x-1.5 gap-y-1', className)}>
      {PIPELINE_STAGES.map((stage, index) => {
        const passed = Boolean(pipeline[stage.key as keyof DetectionPipeline])

        return (
          <li key={stage.key} className="flex items-center gap-1.5">
            <span className="inline-flex items-center gap-1">
              <span
                aria-hidden
                className={cn(
                  'size-1.5 rounded-full',
                  passed ? 'bg-status-success' : 'bg-white/25',
                )}
              />
              <span className={cn('text-2xs', passed ? 'text-white/75' : 'text-white/35')}>
                {stage.label}
              </span>
              <span className="sr-only">{passed ? ' passed' : ' not reached'}</span>
            </span>
            {index < PIPELINE_STAGES.length - 1 && (
              <ChevronRight size={10} aria-hidden className="text-white/25" />
            )}
          </li>
        )
      })}
    </ul>
  )
}
