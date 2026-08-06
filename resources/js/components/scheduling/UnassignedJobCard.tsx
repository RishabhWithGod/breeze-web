import { ChevronRight, Clock, User } from 'lucide-react'
import { Button } from '@/components/common'
import { PRIORITY_SHORT_LABEL, PRIORITY_STRIPE, routeTo } from '@/constants'
import type { SchedulableJob } from '@/types'
import { cn } from '@/utils'

const PRIORITY_TEXT: Record<SchedulableJob['priority'], string> = {
  high: 'text-status-warning',
  medium: 'text-brand',
  low: 'text-status-success',
}

export interface UnassignedJobCardProps {
  job: SchedulableJob
  onAssign: (job: SchedulableJob) => void
  index?: number
}

/**
 * Compact card for the strip beneath the calendar.
 *
 * Deliberately smaller than the queue row on the Unassigned Jobs screen: here it
 * only has to be enough to decide whether to book the job now, with the full detail
 * one click away.
 */
export function UnassignedJobCard({ job, onAssign, index = 0 }: UnassignedJobCardProps) {
  return (
    <div
      className={cn(
        'relative flex flex-col rounded-panel border border-hairline bg-white/8 py-4 pr-4 pl-5',
        'transition-colors hover:border-brand/40 hover:bg-white/12',
      )}
      style={{ animationDelay: `${index * 60}ms` }}
    >
      <span
        aria-hidden
        className={cn(
          'absolute inset-y-0 left-0 w-[3px] rounded-l-panel',
          PRIORITY_STRIPE[job.priority],
        )}
      />

      <div className="flex items-start justify-between gap-3">
        <h4 className="text-base font-semibold text-white">{job.name}</h4>
        <span
          className={cn('shrink-0 text-sm font-medium', PRIORITY_TEXT[job.priority])}
        >
          {PRIORITY_SHORT_LABEL[job.priority]}
        </span>
      </div>

      <dl className="mt-3 space-y-1 text-sm text-white/65">
        <div className="flex items-center gap-2">
          <Clock size={13} aria-hidden className="text-white/45" />
          <dt className="sr-only">Duration</dt>
          <dd>
            {job.estimatedHours === null
              ? 'Duration not estimated'
              : `Duration: ${job.estimatedHours} hours`}
          </dd>
        </div>
        <div className="flex items-center gap-2">
          <User size={13} aria-hidden className="text-white/45" />
          <dt className="sr-only">Client</dt>
          <dd className="truncate">Client: {job.client ?? 'Unassigned'}</dd>
        </div>
      </dl>

      <div className="mt-4 flex items-center justify-between gap-3">
        <Button size="sm" onClick={() => onAssign(job)}>
          Assign
        </Button>
        <a
          href={routeTo.job(job.id)}
          className="inline-flex items-center gap-1 text-sm font-medium text-brand transition-colors hover:text-white"
        >
          View Details
          <ChevronRight size={14} aria-hidden />
        </a>
      </div>
    </div>
  )
}
