import { motion } from 'framer-motion'
import { FolderKanban } from 'lucide-react'
import { Button, ButtonLink, StatusChip } from '@/components/common'
import { routeTo } from '@/constants'
import type { ProjectListRow } from '@/types'
import {
  JOB_TYPE_LABEL,
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  cn,
} from '@/utils'

export interface ProjectListCardProps {
  project: ProjectListRow
  index?: number
  onDelete: (project: ProjectListRow) => void
  className?: string
}

/**
 * Small-screen equivalent of a Projects table row: the same fields and the same
 * actions, so nothing is gated behind horizontal scrolling on a phone.
 */
export function ProjectListCard({
  project,
  index = 0,
  onDelete,
  className,
}: ProjectListCardProps) {
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
          <FolderKanban size={15} aria-hidden />
        </span>

        <div className="min-w-0 flex-1">
          <p className="font-bold text-white">{project.name}</p>
          {/* The name is the client's own — only a project number adds anything. */}
          {project.code && (
            <p className="mt-0.5 truncate text-sm text-white/80">{project.code}</p>
          )}
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
          <dt className="text-white/70">Drawings</dt>
          <dd className="tabular-nums text-white">{project.documentsCount}</dd>
        </div>
        {project.projectType && (
          <div className="flex gap-2">
            <dt className="text-white/70">Type</dt>
            <dd className="text-white">{JOB_TYPE_LABEL[project.projectType]}</dd>
          </div>
        )}
      </dl>

      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-hairline pt-3">
        <ButtonLink href={routeTo.project(project.id)} size="sm">
          Open
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
