import { Trash2 } from 'lucide-react'
import { PRIORITY_STRIPE } from '@/constants'
import type { JobShift } from '@/types'
import { cn } from '@/utils'

export interface ShiftBlockProps {
  shift: JobShift
  /** Opens the job's detail. */
  onSelect: (shift: JobShift) => void
  onRemove: (shift: JobShift) => void
  /** Dims the block on a padding day, so the current period reads first. */
  muted?: boolean
}

/**
 * One crew shift on the calendar.
 *
 * The coloured stripe down the left is the job's priority — the block is too small
 * for a word, and colour is the only thing that survives at this size. The crew
 * lead is shown with initials rather than a photo: avatars would need a per-member
 * asset the product does not have, and initials never fail to load.
 */
export function ShiftBlock({ shift, onSelect, onRemove, muted = false }: ShiftBlockProps) {
  return (
    <div
      className={cn(
        'group/shift relative rounded-panel bg-white/10 pr-2 pl-3 transition-colors',
        'hover:bg-white/20',
        muted && 'opacity-45',
      )}
    >
      {/* Stripe is decorative: the priority is named in the button's own label. */}
      <span
        aria-hidden
        className={cn(
          'absolute inset-y-0 left-0 w-[3px] rounded-l-panel',
          PRIORITY_STRIPE[shift.priority],
        )}
      />

      <button
        type="button"
        onClick={() => onSelect(shift)}
        className="block w-full py-2 text-left focus-visible:outline-none"
      >
        <span className="block truncate text-xs font-semibold text-white">
          {shift.jobName}
        </span>
        <span className="mt-0.5 block truncate text-2xs text-white/90">
          {shift.crew} · {shift.startLabel}
        </span>

        {shift.member && (
          <span className="mt-1.5 flex items-center gap-1.5">
            <span className="grid size-5 shrink-0 place-items-center rounded-full bg-ocean-800 text-[9px] font-semibold text-white ring-1 ring-steel-600">
              {shift.member.initials}
            </span>
            <span className="truncate text-2xs font-medium text-white">
              {shift.member.name}
            </span>
          </span>
        )}
      </button>

      {/* Revealed on hover/focus so the dense grid stays readable. */}
      <button
        type="button"
        onClick={() => onRemove(shift)}
        aria-label={`Remove ${shift.crew} from ${shift.jobName} on ${shift.date}`}
        className={cn(
          'absolute top-1.5 right-1.5 rounded-full p-1 text-white/85 opacity-0 transition',
          'hover:bg-status-danger/25 hover:text-white',
          'group-hover/shift:opacity-100 focus-visible:opacity-100 focus-visible:outline-none',
        )}
      >
        <Trash2 size={12} aria-hidden />
      </button>
    </div>
  )
}
