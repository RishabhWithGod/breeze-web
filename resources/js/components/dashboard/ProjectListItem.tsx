import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { ChevronRight, FileText } from 'lucide-react'
import { ProgressBar, StatusChip } from '@/components/common'
import type { TakeoffHistoryRow } from '@/types'
import {
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  cn,
  formatDate,
  formatNumber,
} from '@/utils'

export interface ProjectListItemProps {
  project: TakeoffHistoryRow
  href: string
  index?: number
  /** Completion percentage — renders a progress bar when provided. */
  progress?: number
  /** Sheet count shown instead of the item count when provided. */
  drawings?: number
  className?: string
}

/**
 * Compact project row. Doubles as the small-screen card view for the
 * dashboard's recent-projects table.
 */
export function ProjectListItem({
  project,
  href,
  index = 0,
  progress,
  drawings,
  className,
}: ProjectListItemProps) {
  const tone = TAKEOFF_STATUS_TONE[project.status]
  const label = TAKEOFF_STATUS_LABEL[project.status]

  return (
    <motion.li
      initial={{ opacity: 0, y: 10 }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true }}
      transition={{ duration: 0.3, delay: index * 0.05 }}
    >
      <Link
        href={href}
        className={cn(
          'group block rounded-panel border border-hairline bg-navy-950/30 p-4',
          // `overflow-hidden` keeps the truncated title from inflating the
          // row's min-content width and widening its grid track.
          'overflow-hidden transition-colors hover:border-brand/45 hover:bg-white/8',
          className,
        )}
      >
        <div className="flex items-center gap-4">
          <span className="grid size-10 shrink-0 place-items-center rounded-panel bg-brand/15 text-brand">
            <FileText size={18} aria-hidden />
          </span>

          <div className="min-w-0 flex-1">
            <p className="truncate text-md font-semibold text-white">{project.name}</p>
            <p className="mt-0.5 truncate text-sm text-white/55">
              {project.client} · {formatDate(project.date)}
            </p>
          </div>

          <div className="hidden shrink-0 text-right sm:block">
            <StatusChip tone={tone} label={label} pulse={project.status === 'processing'} />
            <p className="mt-1 text-xs text-white/45">
              {typeof drawings === 'number'
                ? `${drawings} drawings`
                : `${formatNumber(project.items)} items`}
            </p>
          </div>

          <ChevronRight
            size={18}
            aria-hidden
            className="shrink-0 text-white/35 transition-transform group-hover:translate-x-1 group-hover:text-brand"
          />
        </div>

        {/* Status repeats below the title on the narrowest screens, where the
            right-hand column is hidden. */}
        <div className="mt-3 sm:hidden">
          <StatusChip tone={tone} label={label} pulse={project.status === 'processing'} />
        </div>

        {typeof progress === 'number' && (
          <ProgressBar
            value={progress}
            size="sm"
            tone={project.status === 'failed' ? 'danger' : 'brand'}
            className="mt-3"
          />
        )}
      </Link>
    </motion.li>
  )
}
