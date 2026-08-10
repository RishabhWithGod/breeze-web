import { formatDistanceToNow } from 'date-fns'
import { EmptyState } from '@/components/common'
import { HISTORY_TONE } from '@/constants'
import type { ApprovalHistoryEntry } from '@/types'
import { TONE_DOT_CLASS, cn } from '@/utils'

export interface ApprovalHistoryPanelProps {
  entries: readonly ApprovalHistoryEntry[]
  className?: string
}

/**
 * The takeoff's audit trail: every approval, rejection, rename, count change,
 * merge, split, note, finalisation, job, estimate and assignment.
 *
 * Rows are read-only by design — `approval_histories` is append-only.
 */
export function ApprovalHistoryPanel({ entries, className }: ApprovalHistoryPanelProps) {
  if (entries.length === 0) {
    return (
      <EmptyState
        title="No activity yet"
        description="Decisions on this takeoff will be recorded here as they happen."
      />
    )
  }

  return (
    <ol className={cn('flex flex-col gap-3', className)}>
      {entries.map((entry) => (
        <li key={entry.id} className="flex min-w-0 gap-3">
          <span
            aria-hidden
            className={cn(
              'mt-1.5 size-2 shrink-0 rounded-full',
              TONE_DOT_CLASS[HISTORY_TONE[entry.action] ?? 'neutral'],
            )}
          />
          <div className="min-w-0 flex-1">
            <p className="text-sm text-white">{entry.description}</p>
            <p className="mt-0.5 text-2xs text-white/70">
              {entry.actor ? `${entry.actor} · ` : ''}
              {formatDistanceToNow(new Date(entry.timestamp), { addSuffix: true })}
              {entry.from && entry.to ? ` · ${entry.from} → ${entry.to}` : ''}
            </p>
          </div>
        </li>
      ))}
    </ol>
  )
}
