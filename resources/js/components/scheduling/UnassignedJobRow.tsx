import { Link } from '@inertiajs/react'
import { CalendarDays, ChevronRight, Info } from 'lucide-react'
import { Button } from '@/components/common'
import { routeTo } from '@/constants'
import type { JobPriority, SchedulableJob } from '@/types'
import { cn, formatCurrency, formatDate } from '@/utils'

/**
 * The bubble carries the priority on its own now that the word beside it is
 * gone — a colour per level, readable as a solid fill with a white glyph.
 */
const PRIORITY_STYLES: Record<JobPriority, { bubble: string }> = {
  high: { bubble: 'bg-status-danger text-white' },
  medium: { bubble: 'bg-status-warning text-brand-ink' },
  low: { bubble: 'bg-status-success text-brand-ink' },
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
            </p>
          </div>
        </div>

        <Button size="sm" className="shrink-0" onClick={() => onSchedule(job)}>
          Schedule
        </Button>
      </div>

      <dl className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          {/* Who the work is handed to — the first thing the office needs to
              know before deciding when to book it. */}
          <dt className="text-sm text-white/75">Team</dt>
          <dd className="mt-0.5 truncate text-md font-semibold text-white">
            {job.teamName ?? <span className="font-normal text-white/50">No team</span>}
          </dd>
        </div>
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

      {(job.supervisors.length > 0 || job.foremen.length > 0) && (
        <div className="mt-5 grid gap-4 sm:grid-cols-2">
          {/*
            Named rather than counted: "who is on this" is the question, and two
            names take less room than a number you have to open the job to read.
          */}
          <div>
            <p className="text-sm text-white/75">Supervisor</p>
            <p className="mt-0.5 text-md font-semibold text-white">
              {job.supervisors.length > 0
                ? job.supervisors.map((person) => person.name).join(', ')
                : <span className="font-normal text-white/50">None assigned</span>}
            </p>
          </div>
          <div>
            <p className="text-sm text-white/75">Foreman</p>
            <p className="mt-0.5 text-md font-semibold text-white">
              {job.foremen.length > 0
                ? job.foremen.map((person) => person.name).join(', ')
                : <span className="font-normal text-white/50">None assigned</span>}
            </p>
          </div>
        </div>
      )}

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
          href={routeTo.jobFrom(job.id, 'scheduling')}
          className="inline-flex items-center gap-1 text-sm font-medium text-brand transition-colors hover:text-white"
        >
          View Details
          <ChevronRight size={14} aria-hidden />
        </Link>
      </div>
    </article>
  )
}
