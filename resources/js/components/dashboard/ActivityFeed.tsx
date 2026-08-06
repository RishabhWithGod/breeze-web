import { motion } from 'framer-motion'
import {
  CheckCircle2,
  CircleDot,
  Info,
  TriangleAlert,
  type LucideIcon,
} from 'lucide-react'
import type { ActivityEntry } from '@/types'
import { cn, formatRelative } from '@/utils'

const TONE_ICON: Record<ActivityEntry['tone'], LucideIcon> = {
  success: CheckCircle2,
  warning: TriangleAlert,
  brand: CircleDot,
  info: Info,
}

const TONE_COLOR: Record<ActivityEntry['tone'], string> = {
  success: 'bg-status-success/15 text-status-success',
  warning: 'bg-status-warning/15 text-status-warning',
  brand: 'bg-brand/15 text-brand',
  info: 'bg-status-info/15 text-status-info',
}

export interface ActivityFeedProps {
  entries: readonly ActivityEntry[]
  className?: string
}

/** Vertical timeline of recent events. */
export function ActivityFeed({ entries, className }: ActivityFeedProps) {
  return (
    <ol className={cn('relative space-y-1', className)}>
      {entries.map((entry, index) => {
        const Icon = TONE_ICON[entry.tone]
        const isLast = index === entries.length - 1

        return (
          <motion.li
            key={entry.id}
            initial={{ opacity: 0, x: -8 }}
            whileInView={{ opacity: 1, x: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.3, delay: index * 0.05 }}
            className="relative flex gap-4 pb-5 last:pb-0"
          >
            {!isLast && (
              <span
                className="absolute top-9 bottom-0 left-[15px] w-px bg-hairline-strong"
                aria-hidden
              />
            )}

            <span
              className={cn(
                'relative z-1 grid size-8 shrink-0 place-items-center rounded-full',
                TONE_COLOR[entry.tone],
              )}
            >
              <Icon size={15} aria-hidden />
            </span>

            <div className="min-w-0 pt-0.5">
              <p className="text-md font-medium text-white">{entry.title}</p>
              <p className="mt-0.5 text-sm text-white/60">{entry.description}</p>
              <p className="mt-1 text-xs text-white/40">
                {formatRelative(entry.timestamp)}
              </p>
            </div>
          </motion.li>
        )
      })}
    </ol>
  )
}
