import { Card, SectionHeading } from '@/components/common'
import type { PipelineStage, Tone } from '@/types'
import { TONE_DOT_CLASS, cn } from '@/utils'

/**
 * How the engine's own words map onto the design system's tones.
 *
 * Anything unrecognised is shown neutral rather than guessed at, so a new status
 * from the engine still renders honestly.
 */
const STATUS_TONE: Record<string, Tone> = {
  ok: 'success',
  parsed: 'success',
  complete: 'success',
  partial: 'warning',
  fallback: 'warning',
  skipped: 'neutral',
  missing: 'neutral',
  failed: 'danger',
  error: 'danger',
}

const STAGE_LABEL: Record<string, string> = {
  legend: 'Legend',
  template: 'Template',
  vector: 'Vector',
  vision: 'Vision',
  tables: 'Tables',
}

export interface PipelineStatusProps {
  stages: readonly PipelineStage[]
  /** Seconds the engine spent on the drawing. */
  processingTime?: number | null
  /** Crop tallies from the engine's lifecycle, when available. */
  statistics?: Readonly<Record<string, number>> | null
  className?: string
}

/** The engine's per-detector pipeline status for a run. */
export function PipelineStatus({
  stages,
  processingTime,
  statistics,
  className,
}: PipelineStatusProps) {
  if (stages.length === 0) return null

  return (
    <Card padding="md" className={className}>
      <SectionHeading
        as="h3"
        title="Pipeline"
        subtitle={
          processingTime
            ? `Reported by the AI engine · analysed in ${processingTime.toFixed(1)}s`
            : 'Reported by the AI engine'
        }
      />

      <ul className="flex flex-wrap gap-2">
        {stages.map((stage) => {
          const tone = STATUS_TONE[stage.status.toLowerCase()] ?? 'neutral'

          return (
            <li
              key={stage.stage}
              className="flex min-w-0 items-center gap-2 rounded-panel bg-white/5 px-3 py-2"
            >
              <span
                aria-hidden
                className={cn('size-2 shrink-0 rounded-full', TONE_DOT_CLASS[tone])}
              />
              <span className="text-md text-white">
                {STAGE_LABEL[stage.stage] ?? stage.stage}
              </span>
              <span className="text-2xs tracking-wide text-white/80 uppercase">
                {stage.status}
              </span>
            </li>
          )
        })}
      </ul>

      {statistics && Object.keys(statistics).length > 0 && (
        <dl className="mt-4 flex flex-wrap gap-x-6 gap-y-2 border-t border-hairline pt-4">
          {Object.entries(statistics).map(([label, value]) => (
            <div key={label} className="min-w-0">
              <dt className="text-2xs tracking-wide text-white/75 uppercase">
                {label.replace(/_/g, ' ')}
              </dt>
              <dd className="text-md font-semibold text-white">{value}</dd>
            </div>
          ))}
        </dl>
      )}
    </Card>
  )
}
