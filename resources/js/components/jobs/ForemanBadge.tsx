import type { JobForeman } from '@/types'
import { cn } from '@/utils'

export interface ForemanBadgeProps {
  /** Only the display fields — the id is irrelevant to the badge. */
  foreman: Pick<JobForeman, 'name' | 'initials'>
  className?: string
}

/** Avatar + name pair used in the Foreman column. */
export function ForemanBadge({ foreman, className }: ForemanBadgeProps) {
  return (
    <span className={cn('inline-flex items-center gap-2.5', className)}>
      <span className="grid size-7 shrink-0 place-items-center rounded-full bg-ocean-800 text-2xs font-semibold text-white ring-1 ring-steel-600">
        {foreman.initials}
      </span>
      <span className="truncate font-semibold text-white">{foreman.name}</span>
    </span>
  )
}
