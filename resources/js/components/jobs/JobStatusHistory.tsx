import { ArrowRight } from 'lucide-react'
import { StatusChip } from '@/components/common'
import type { JobStatusChange } from '@/types'
import { JOB_STATUS_LABEL, JOB_STATUS_TONE, formatDate } from '@/utils'

export interface JobStatusHistoryProps {
  history: readonly JobStatusChange[]
}

/** Every status transition, newest first, from the job_status_changes table. */
export function JobStatusHistory({ history }: JobStatusHistoryProps) {
  if (history.length === 0) {
    return <p className="text-md text-white/50">No status changes recorded yet.</p>
  }

  return (
    <ul className="space-y-3">
      {history.map((change) => (
        <li
          key={change.id}
          className="flex flex-wrap items-center gap-3 rounded-panel border border-hairline bg-white/4 p-4"
        >
          {change.from ? (
            <>
              <StatusChip
                hideDot
                tone={JOB_STATUS_TONE[change.from]}
                label={JOB_STATUS_LABEL[change.from]}
              />
              <ArrowRight size={14} aria-hidden className="text-white/35" />
            </>
          ) : (
            <span className="text-sm text-white/45">Created as</span>
          )}

          <StatusChip
            hideDot
            tone={JOB_STATUS_TONE[change.to]}
            label={JOB_STATUS_LABEL[change.to]}
          />

          <span className="ml-auto text-sm text-white/45">
            {change.actor} · {formatDate(change.createdAt, 'MMM d, yyyy · h:mm a')}
          </span>
        </li>
      ))}
    </ul>
  )
}
