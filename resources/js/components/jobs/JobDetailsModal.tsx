import { CalendarDays, HardHat, Wallet } from 'lucide-react'
import { Button, ButtonLink, Modal, StatusChip } from '@/components/common'
import { ROUTES } from '@/constants'
import type { Job } from '@/types'
import {
  JOB_STATUS_LABEL,
  JOB_STATUS_TONE,
  formatCurrency,
  formatDate,
} from '@/utils'
import { ForemanBadge } from './ForemanBadge'

export interface JobDetailsModalProps {
  job: Job | null
  onClose: () => void
}

/** Read-only job summary opened by the View action. */
export function JobDetailsModal({ job, onClose }: JobDetailsModalProps) {
  return (
    <Modal
      isOpen={Boolean(job)}
      onClose={onClose}
      size="md"
      title={job?.name ?? 'Job'}
      description="Job overview — read-only in this prototype."
      footer={
        <>
          <Button variant="secondary" size="sm" onClick={onClose}>
            Close
          </Button>
          <ButtonLink href={ROUTES.results} size="sm" onClick={onClose}>
            Open takeoff
          </ButtonLink>
        </>
      }
    >
      {job && (
        <div className="space-y-5">
          <StatusChip
            tone={JOB_STATUS_TONE[job.status]}
            label={JOB_STATUS_LABEL[job.status]}
            pulse={job.status === 'in-progress'}
          />

          <dl className="grid gap-4 sm:grid-cols-2">
            <div className="rounded-panel border border-hairline bg-white/4 p-4">
              <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                <HardHat size={14} aria-hidden className="text-brand" />
                Foreman
              </dt>
              <dd className="mt-2">
                {job.foreman ? (
                  <ForemanBadge foreman={job.foreman} />
                ) : (
                  <span className="text-md text-white/70">Unassigned</span>
                )}
              </dd>
            </div>

            <div className="rounded-panel border border-hairline bg-white/4 p-4">
              <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                <Wallet size={14} aria-hidden className="text-brand" />
                Budget
              </dt>
              <dd className="mt-2 text-lg font-semibold tabular-nums text-white">
                {job.budget === null ? '—' : formatCurrency(job.budget, 2)}
              </dd>
            </div>

            <div className="rounded-panel border border-hairline bg-white/4 p-4">
              <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                <CalendarDays size={14} aria-hidden className="text-brand" />
                Start date
              </dt>
              <dd className="mt-2 text-md text-white">
                {job.startDate ? formatDate(job.startDate) : '—'}
              </dd>
            </div>

            <div className="rounded-panel border border-hairline bg-white/4 p-4">
              <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                <CalendarDays size={14} aria-hidden className="text-brand" />
                End date
              </dt>
              <dd className="mt-2 text-md text-white">
                {job.endDate ? formatDate(job.endDate) : '—'}
              </dd>
            </div>
          </dl>
        </div>
      )}
    </Modal>
  )
}
