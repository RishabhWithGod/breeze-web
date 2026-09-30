import { motion } from 'framer-motion'
import { GitBranch, Trash2 } from 'lucide-react'
import { Button, ButtonLink, MoreMenu, StatusChip } from '@/components/common'
import { routeTo } from '@/constants'
import type { TakeoffHistoryRow } from '@/types'
import {
  HISTORY_STATUS_LABEL,
  HISTORY_STATUS_TONE,
  cn,
  formatDate,
} from '@/utils'

export interface ProjectHistoryCardProps {
  project: TakeoffHistoryRow
  index?: number
  onDelete: (project: TakeoffHistoryRow) => void
  onRetry: (project: TakeoffHistoryRow) => void
  className?: string
}

/**
 * Small-screen equivalent of a history table row. Carries the same fields
 * and actions as the table, so nothing is gated behind horizontal scrolling
 * on a phone.
 */
export function ProjectHistoryCard({
  project,
  index = 0,
  onDelete,
  onRetry,
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
        <span className="grid size-9 shrink-0 place-items-center rounded-panel bg-purple-400/15 text-purple-300 ring-1 ring-purple-400/40">
          <GitBranch size={17} aria-hidden />
        </span>

        <div className="min-w-0 flex-1">
          <p className="truncate font-bold text-white">
            {project.drawingName ?? 'Untitled drawing'}
          </p>
          <p className="truncate text-xs text-white/60">
            {project.name} · {project.client}
          </p>
        </div>

        <StatusChip
          pill
          tone={HISTORY_STATUS_TONE[project.reviewStatus]}
          label={HISTORY_STATUS_LABEL[project.reviewStatus]}
          className="shrink-0 text-sm"
        />
      </div>

      <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
        <div className="flex gap-2">
          <dt className="text-white/70">Uploaded</dt>
          <dd className="text-white">{formatDate(project.uploadedAt)}</dd>
        </div>
        <div className="flex gap-2">
          <dt className="text-white/70">Pages</dt>
          <dd className="text-white">{project.pageCount}</dd>
        </div>
      </dl>

      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-hairline pt-3">
        <ButtonLink href={`${routeTo.drawingDetails(project.id)}?from=history`} variant="purple" size="sm">
          View
        </ButtonLink>

        {project.reviewStatus === 'failed' && (
          <Button variant="white" size="sm" onClick={() => onRetry(project)}>
            Retry
          </Button>
        )}

        <MoreMenu
          className="ml-auto"
          variant="minimal"
          ariaLabel={`More actions for ${project.name}`}
          items={[
            {
              label: 'Delete',
              icon: Trash2,
              destructive: true,
              onSelect: () => onDelete(project),
            },
          ]}
        />
      </div>
    </motion.li>
  )
}
