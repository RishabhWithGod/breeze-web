import { motion } from 'framer-motion'
import { FeedIcon } from '@/lib/icons'
import type { FeedItem, TileTone } from '@/types'
import { cn } from '@/utils'

const TILE_TONES: Record<TileTone, string> = {
  lilac: 'bg-tile-lilac text-navy-800',
  butter: 'bg-tile-butter text-navy-800',
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
        'flex items-start gap-4 rounded-panel border border-hairline bg-white/4 p-4',
        'transition-colors duration-200 hover:border-brand/35 hover:bg-white/12',
        className,
      )}
    >
      <span
        className={cn(
          'grid size-9 shrink-0 place-items-center rounded-panel',
          TILE_TONES[row.tile],
        )}
      >
        {/* Rows come from the database, so the icon arrives as a key. */}
        <FeedIcon name={row.icon} size={18} aria-hidden />
      </span>

      <div className="min-w-0 flex-1">
        <p className="text-md text-white">
          {row.segments.map((segment, segmentIndex) => (
            <span
              key={`${row.id}-${segmentIndex}`}
              className={segment.strong ? 'font-semibold' : undefined}
            >
              {segment.text}
            </span>
          ))}
        </p>

        {row.detail && <p className="mt-0.5 text-md text-white">{row.detail}</p>}
        {row.meta && <p className="mt-1.5 text-sm text-white/75">{row.meta}</p>}
      </div>
    </motion.li>
  )
}
