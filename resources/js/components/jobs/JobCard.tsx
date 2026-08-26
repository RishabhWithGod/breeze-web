import { motion } from 'framer-motion'
import { Badge, Button, StatusChip, StatusDot } from '@/components/common'
import type { Job } from '@/types'
import {
  JOB_STATUS_LABEL,
  JOB_STATUS_TONE,
  cn,
  formatCurrency,
  formatDate,
} from '@/utils'

export interface JobCardProps {
  job: Job
  index?: number
  onView: (job: Job) => void
  onDelete: (job: Job) => void
  className?: string
}

/**
 * Small-screen equivalent of a jobs table row — same seven fields and the same
 * two actions, so nothing hides behind a sideways scroll on a phone.
 */
export function JobCard({ job, index = 0, onView, onDelete, className }: JobCardProps) {
  const tone = JOB_STATUS_TONE[job.status]

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
      <div className="flex items-start justify-between gap-3">
        <p className="flex min-w-0 items-center gap-2.5 font-bold text-white">
          <StatusDot tone={tone} />
          <span className="min-w-0">{job.name}</span>
        </p>
        <StatusChip
          hideDot
          tone={tone}
          label={JOB_STATUS_LABEL[job.status]}
          className="shrink-0 text-sm"
        />
      </div>

      <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        <div>
          <dt className="text-white/70">Foreman</dt>
          <dd className="mt-0.5 truncate text-white">
            {job.foreman?.name ?? 'Unassigned'}
          </dd>
        </div>
        <div>
          <dt className="text-white/70">Budget</dt>
          <dd className="mt-0.5 tabular-nums text-white">
            {job.budget === null ? '—' : formatCurrency(job.budget, 2)}
          </dd>
        </div>
        <div>
          <dt className="text-white/70">Start date</dt>
          <dd className="mt-0.5 text-white">
            {job.startDate ? formatDate(job.startDate) : '—'}
          </dd>
        </div>
        <div>
          <dt className="text-white/70">End date</dt>
          <dd className="mt-0.5 text-white">
            {job.endDate ? formatDate(job.endDate) : '—'}
          </dd>
        </div>
      </dl>

      {job.assignments.length > 0 && (
        <div className="mt-3">
          <p className="text-sm text-white/70">Assigned</p>
          <div className="mt-1 flex flex-wrap gap-1.5">
            {job.assignments.map((assignment) => (
              <Badge key={assignment.id} tone="info" size="sm">
                {assignment.name} · {assignment.roleLabel}
              </Badge>
            ))}
          </div>
        </div>
      )}

      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-hairline pt-3">
        <Button size="sm" onClick={() => onView(job)}>
          View
        </Button>
        <Button
          variant="white"
          size="sm"
          className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
          onClick={() => onDelete(job)}
        >
          Delete
        </Button>
      </div>
    </motion.li>
  )
}
