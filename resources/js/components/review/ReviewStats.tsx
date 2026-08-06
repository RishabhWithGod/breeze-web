import { Card } from '@/components/common'
import type { ReviewTally, Tone } from '@/types'
import { cn } from '@/utils'

const TONE_TEXT: Record<Tone, string> = {
  brand: 'text-brand',
  success: 'text-status-success',
  warning: 'text-status-warning',
  danger: 'text-red-300',
  info: 'text-status-info',
  neutral: 'text-white',
}

export interface ReviewStatsProps {
  tally: ReviewTally
  /** Quantity the AI originally reported, for the comparison line. */
  aiItemTotal?: number
  className?: string
}

/**
 * Review progress at a glance. `approvedCount` is the quantity heading for the
 * final JSON, which is the number that matters — it can differ from the count of
 * approved cards once quantities have been edited.
 */
export function ReviewStats({ tally, aiItemTotal, className }: ReviewStatsProps) {
  const stats: readonly { label: string; value: number; tone: Tone; hint?: string }[] = [
    { label: 'Detections', value: tally.total, tone: 'neutral' },
    { label: 'Pending', value: tally.pending, tone: 'warning' },
    { label: 'Approved', value: tally.approved, tone: 'success' },
    { label: 'Rejected', value: tally.rejected, tone: 'danger' },
    { label: 'Modified', value: tally.modified, tone: 'info' },
    {
      label: 'Items in final JSON',
      value: tally.approvedCount,
      tone: 'brand',
      ...(aiItemTotal !== undefined ? { hint: `AI reported ${aiItemTotal}` } : {}),
    },
  ]

  return (
    <div className={cn('grid gap-3 sm:grid-cols-3 xl:grid-cols-6', className)}>
      {stats.map((stat, index) => (
        <Card key={stat.label} padding="sm" index={index} className="min-w-0">
          <p className="truncate text-2xs tracking-wide text-white/55 uppercase">
            {stat.label}
          </p>
          <p className={cn('mt-1 text-2xl font-bold', TONE_TEXT[stat.tone])}>{stat.value}</p>
          {stat.hint && <p className="text-2xs text-white/45">{stat.hint}</p>}
        </Card>
      ))}
    </div>
  )
}
