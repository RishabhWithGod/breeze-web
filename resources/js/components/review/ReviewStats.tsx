import { CheckCircle2, CircleDashed, Layers, XCircle, type LucideIcon } from 'lucide-react'
import { Card, ProgressBar } from '@/components/common'
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

const TONE_ICON: Record<Tone, string> = {
  brand: 'bg-brand/15 text-brand',
  success: 'bg-status-success/15 text-status-success',
  warning: 'bg-status-warning/15 text-status-warning',
  danger: 'bg-status-danger/20 text-red-300',
  info: 'bg-status-info/15 text-status-info',
  neutral: 'bg-white/10 text-white/90',
}

export interface ReviewStatsProps {
  tally: ReviewTally
  /** Quantity the AI originally reported, shown as the comparison line. */
  aiItemTotal?: number
  className?: string
}

/**
 * Where the review stands, in the four numbers a reviewer is actually tracking.
 *
 * Deliberately four rather than six. "Modified" and "items in the final JSON" were
 * both counts of the machinery rather than of the work — the first is visible on the
 * cards that changed, and the second is the quantity total, which now reads as a
 * progress line instead of a tile competing with the decisions still to make.
 */
export function ReviewStats({ tally, aiItemTotal, className }: ReviewStatsProps) {
  const stats: readonly {
    label: string
    value: number
    tone: Tone
    icon: LucideIcon
    hint?: string
  }[] = [
    { label: 'Total Symbols', value: tally.total, tone: 'neutral', icon: Layers },
    { label: 'Approved', value: tally.approved, tone: 'success', icon: CheckCircle2 },
    { label: 'Needs Review', value: tally.pending, tone: 'warning', icon: CircleDashed },
    { label: 'Rejected', value: tally.rejected, tone: 'danger', icon: XCircle },
  ]

  const decided = tally.total - tally.pending
  const donePct = tally.total > 0 ? Math.round((decided / tally.total) * 100) : 0

  return (
    <div className={className}>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {stats.map((stat, index) => (
          <Card key={stat.label} padding="md" index={index} className="min-w-0">
            <div className="flex items-start justify-between gap-3">
              <p className="truncate text-md text-white/85">{stat.label}</p>
              <span
                aria-hidden
                className={cn(
                  'grid size-8 shrink-0 place-items-center rounded-full',
                  TONE_ICON[stat.tone],
                )}
              >
                <stat.icon size={16} />
              </span>
            </div>
            <p className={cn('mt-3 text-3xl font-bold tabular-nums', TONE_TEXT[stat.tone])}>
              {stat.value}
            </p>
          </Card>
        ))}
      </div>

      {/*
        The quantity heading for the estimate, and how far through the review is.
        Both were tiles; as a line they inform without competing.
      */}
      <Card padding="md" className="mt-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-md text-white/85">Review progress</p>
            <p className="mt-0.5 text-lg font-semibold text-white">
              {decided} of {tally.total} symbols decided
            </p>
          </div>
          <div className="text-left sm:text-right">
            <p className="text-md text-white/85">Quantity for the estimate</p>
            <p className="mt-0.5 text-lg font-semibold text-white tabular-nums">
              {tally.approvedCount}
              {aiItemTotal !== undefined && aiItemTotal !== tally.approvedCount && (
                <span className="ml-2 text-sm font-normal text-white/75">
                  AI counted {aiItemTotal}
                </span>
              )}
            </p>
          </div>
        </div>
        <ProgressBar
          value={donePct}
          tone={donePct === 100 ? 'success' : 'brand'}
          className="mt-4"
        />
      </Card>
    </div>
  )
}
