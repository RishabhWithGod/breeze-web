import { motion } from 'framer-motion'
import { FileText } from 'lucide-react'
import { Button, ButtonLink, StatusChip } from '@/components/common'
import { routeTo } from '@/constants'
import type { TakeoffHistoryRow } from '@/types'
import {
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  cn,
  formatDate,
  formatNumber,
} from '@/utils'

export interface ProjectHistoryCardProps {
  project: TakeoffHistoryRow
  index?: number
  onDelete: (project: TakeoffHistoryRow) => void
  className?: string
}

/**
 * Small-screen equivalent of a history table row. Carries the same six fields
 * and the same three actions, so nothing is gated behind horizontal scrolling
 * on a phone.
 */
export function ProjectHistoryCard({
  project,
  index = 0,
  onDelete,
  className,
}: ProjectHistoryCardProps) {
  return (
    <motion.li
      initial={{ opacity: 0, y: 10 }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true }}
      transition={{ duration: 0.3, delay: Math.min(index, 6) * 0.05 }}
      className={cn(
        'rounded-panel border border-hairline bg-white/4 p-4 transition-colors hover:border-brand/35',
        className,
      )}
    >
      <div className="flex items-start gap-3">
        <span className="grid size-8 shrink-0 place-items-center rounded-sm bg-ocean-600 text-white">
          <FileText size={15} aria-hidden />
        </span>

        <div className="min-w-0 flex-1">
          <p className="font-bold text-white">{project.name}</p>
          <p className="mt-0.5 truncate text-sm text-white/80">{project.client}</p>
        </div>

        <StatusChip
          hideDot
          tone={TAKEOFF_STATUS_TONE[project.status]}
          label={TAKEOFF_STATUS_LABEL[project.status]}
          className="shrink-0 text-sm"
        />
      </div>

      <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
        <div className="flex gap-2">
          <dt className="text-white/70">Date</dt>
          <dd className="text-white">{formatDate(project.date)}</dd>
        </div>
        <div className="flex gap-2">
          <dt className="text-white/70">Items</dt>
          <dd className="tabular-nums text-white">{formatNumber(project.items)}</dd>
        </div>
      </dl>

      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-hairline pt-3">
        <ButtonLink href={routeTo.drawingDetails(project.id)} size="sm">
          View
        </ButtonLink>
        <Button
          variant="white"
          size="sm"
          className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
          onClick={() => onDelete(project)}
        >
          Delete
        </Button>
      </div>
    </motion.li>
  )
}
