import { motion } from 'framer-motion'
import { FeedIcon } from '@/lib/icons'
import type { FeedItem, TileTone } from '@/types'
import { cn } from '@/utils'

const TILE_TONES: Record<TileTone, string> = {
  lilac: 'bg-tile-lilac text-brand',
  butter: 'bg-tile-butter text-status-warning',
}

export interface IconListRowProps {
  row: FeedItem
  index?: number
  className?: string
}

/**
 * Translucent list row with a pale icon tile. Shared by Recent Activity,
 * Notifications and Upcoming Schedule so the three panels stay identical in
 * rhythm and spacing.
 */
export function IconListRow({ row, index = 0, className }: IconListRowProps) {
  return (
    <motion.li
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      // Capped so a long, scrollable list doesn't queue up seconds of delay
      // for the rows further down — only the first screenful staggers in.
      transition={{ duration: 0.3, delay: Math.min(index, 8) * 0.05 }}
      className={cn(
        'flex items-center gap-3 border-b border-hairline py-3 first:pt-0 last:border-b-0 last:pb-0',
        className,
      )}
    >
      <span className={cn('grid size-8 shrink-0 place-items-center rounded-lg', TILE_TONES[row.tile])}>
        {/* Rows come from the database, so the icon arrives as a key. */}
        <FeedIcon name={row.icon} size={17} aria-hidden />
      </span>

      <div className="min-w-0 flex-1">
        <p className="text-md leading-snug text-white">
          {row.segments.map((segment, segmentIndex) => (
            <span key={`${row.id}-${segmentIndex}`} className={segment.strong ? 'font-semibold' : undefined}>
              {segment.text}
            </span>
          ))}
        </p>

        {row.detail && <p className="mt-0.5 text-sm text-white/65">{row.detail}</p>}
        {row.meta && <p className="mt-0.5 text-xs text-white/55">{row.meta}</p>}
      </div>
    </motion.li>
  )
}
