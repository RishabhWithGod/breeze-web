import { Link } from '@inertiajs/react'
import { CalendarDays, ChevronRight, Info } from 'lucide-react'
import { Button } from '@/components/common'
import { PRIORITY_LABEL, routeTo } from '@/constants'
import type { JobPriority, SchedulableJob } from '@/types'
import { cn, formatCurrency, formatDate } from '@/utils'

/**
 * The bubble and the priority word share a colour per level.
 *
 * The word is a lighter tint than the bubble it sits under: the status reds and
 * violets are picked to carry a white glyph on a solid fill, and as text on this
 * dark background they fall well below a readable contrast ratio.
 */
const PRIORITY_STYLES: Record<JobPriority, { bubble: string; text: string }> = {
  high: { bubble: 'bg-status-danger text-white', text: 'text-red-300' },
  medium: { bubble: 'bg-status-warning text-brand-ink', text: 'text-status-warning' },
  low: { bubble: 'bg-status-success text-brand-ink', text: 'text-status-success' },
}

/** Residential reads green, commercial cyan, industrial violet. */
const TYPE_TEXT: Record<string, string> = {
  residential: 'text-status-success',
  commercial: 'text-brand',
  industrial: 'text-violet-300',
}

export interface UnassignedJobRowProps {
  job: SchedulableJob
  onSchedule: (job: SchedulableJob) => void
  index?: number
}

/**
 * Full-width queue row on the Unassigned Jobs screen.
 *
 * Everything needed to decide who to send: the four figures the office weighs
 * (client, location, hours, value) on one line, and the skills the job needs
 * beneath them — because a crew without them cannot take it.
 */
export function UnassignedJobRow({ job, onSchedule, index = 0 }: UnassignedJobRowProps) {
  const priority = PRIORITY_STYLES[job.priority]

  return (
    <article
      className={cn(
        'rounded-card border border-hairline bg-white/8 p-5 transition-colors',
        'hover:border-brand/40 hover:bg-white/12',
      )}
      style={{ animationDelay: `${index * 50}ms` }}
    >
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 items-start gap-3">
          <span
            aria-hidden
            className={cn(
              'mt-0.5 grid size-8 shrink-0 place-items-center rounded-full',
              priority.bubble,
            )}
          >
            <Info size={16} />
          </span>
          <div className="min-w-0">
            <h3 className="text-lg font-semibold text-white">{job.name}</h3>
            <p className="mt-0.5 flex flex-wrap items-center gap-x-3 text-md font-medium">
              <span className={TYPE_TEXT[job.jobType ?? ''] ?? 'text-white/90'}>
                {job.jobType
                  ? job.jobType.charAt(0).toUpperCase() + job.jobType.slice(1)
                  : 'Unclassified'}
              </span>
              <span className={priority.text}>{PRIORITY_LABEL[job.priority]}</span>
            </p>
          </div>
        </div>

        <Button size="sm" className="shrink-0" onClick={() => onSchedule(job)}>
          Schedule
        </Button>
      </div>

      <dl className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <dt className="text-sm text-white/75">Client</dt>
          <dd className="mt-0.5 truncate text-md font-semibold text-white">
            {job.client ?? '—'}
          </dd>
        </div>
        <div>
          <dt className="text-sm text-white/75">Location</dt>
          <dd className="mt-0.5 truncate text-md font-semibold text-white">
            {job.location ?? '—'}
          </dd>
        </div>
        <div>
          <dt className="text-sm text-white/75">Estimated Hours</dt>
          <dd className="mt-0.5 text-md font-semibold text-white">
            {job.estimatedHours === null
              ? '—'
              : `${job.estimatedHours} hours (${Math.max(1, Math.ceil(job.estimatedHours / 8))} ${
                  Math.ceil(job.estimatedHours / 8) === 1 ? 'day' : 'days'
                })`}
          </dd>
        </div>
        <div>
          <dt className="text-sm text-white/75">Value</dt>
          <dd className="mt-0.5 text-md font-semibold text-white">
            {job.value === null ? '—' : formatCurrency(job.value, 2)}
          </dd>
        </div>
      </dl>

      {job.requiredSkills.length > 0 && (
        <div className="mt-5">
          <p className="text-sm text-white/75">Required Skills</p>
          <ul className="mt-1.5 flex flex-wrap gap-2">
            {job.requiredSkills.map((skill) => (
              <li
                key={skill}
                className="rounded-full border border-hairline bg-white/10 px-3 py-1 text-xs font-medium text-white"
              >
                {skill}
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="mt-5 flex items-center justify-between gap-3 border-t border-hairline pt-4">
        <p className="flex items-center gap-2 text-sm text-white/80">
          <CalendarDays size={14} aria-hidden />
          Created: {job.createdAt ? formatDate(job.createdAt) : 'Unknown'}
        </p>
        <Link
          href={routeTo.job(job.id)}
          className="inline-flex items-center gap-1 text-sm font-medium text-brand transition-colors hover:text-white"
        >
          View Details
          <ChevronRight size={14} aria-hidden />
        </Link>
      </div>
    </article>
  )
}
