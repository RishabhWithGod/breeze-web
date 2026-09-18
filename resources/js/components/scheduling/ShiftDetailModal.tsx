import { Link } from '@inertiajs/react'
import { Badge, Button, Modal } from '@/components/common'
import {
  PRIORITY_LABEL,
  PRIORITY_TONE,
  SCHEDULE_STATUS_LABEL,
  SCHEDULE_STATUS_TONE,
  routeTo,
} from '@/constants'
import type { JobShift } from '@/types'
import { formatDate } from '@/utils'

export interface ShiftDetailModalProps {
  /** The shift being inspected; the modal is closed when this is null. */
  shift: JobShift | null
  onClose: () => void
  onRemove: (shift: JobShift) => void
}

/** Everything about one booked shift, opened from a calendar block. */
export function ShiftDetailModal({ shift, onClose, onRemove }: ShiftDetailModalProps) {
  return (
    <Modal
      isOpen={shift !== null}
      onClose={onClose}
      title={shift?.jobName ?? 'Shift'}
      description={shift ? formatDate(`${shift.date}T00:00:00`, 'EEEE, MM/dd/yyyy') : undefined}
      size="md"
      footer={
        shift && (
          <div className="flex w-full flex-wrap items-center justify-between gap-3">
            <Button variant="danger" size="sm" onClick={() => onRemove(shift)}>
              Remove shift
            </Button>
            <div className="flex items-center gap-3">
              <Link
                href={routeTo.jobFrom(shift.jobId, 'scheduling-calendar')}
                className="text-sm font-medium text-brand transition-colors hover:text-white"
              >
                Open job
              </Link>
              <Button variant="secondary" size="sm" onClick={onClose}>
                Close
              </Button>
            </div>
          </div>
        )
      }
    >
      {shift && (
        <div className="space-y-5">
          <div className="flex flex-wrap gap-2">
            <Badge tone={SCHEDULE_STATUS_TONE[shift.status]}>
              {SCHEDULE_STATUS_LABEL[shift.status]}
            </Badge>
            <Badge tone={PRIORITY_TONE[shift.priority]}>
              {PRIORITY_LABEL[shift.priority]}
            </Badge>
            <Badge tone="neutral">{shift.crew}</Badge>
          </div>

          <dl className="grid gap-4 sm:grid-cols-2">
            <div>
              <dt className="text-sm text-white/75">Time</dt>
              <dd className="mt-0.5 font-semibold text-white">
                {shift.startLabel} – {shift.endLabel}
              </dd>
            </div>
            <div>
              <dt className="text-sm text-white/75">Length</dt>
              <dd className="mt-0.5 font-semibold text-white">
                {shift.durationHours} hours
              </dd>
            </div>
            {/*
              Who is on the work, read off its tasks rather than off the
              booking: staffing changes after a shift is booked, and showing
              last week's answer is worse than showing none.
            */}
            <div>
              <dt className="text-sm text-white/75">Foreman</dt>
              <dd className="mt-0.5 font-semibold text-white">
                {shift.supervisors.length > 0
                  ? shift.supervisors.map((person) => person.name).join(', ')
                  : 'None assigned'}
              </dd>
            </div>
            <div>
              <dt className="text-sm text-white/75">Crew</dt>
              <dd className="mt-0.5 font-semibold text-white">
                {shift.foremen.length > 0
                  ? shift.foremen.map((person) => person.name).join(', ')
                  : 'None assigned'}
              </dd>
            </div>
            <div>
              <dt className="text-sm text-white/75">Crew lead</dt>
              <dd className="mt-0.5 font-semibold text-white">
                {shift.member
                  ? `${shift.member.name}${shift.member.role ? ` — ${shift.member.role}` : ''}`
                  : 'Not yet assigned'}
              </dd>
            </div>
            <div>
              <dt className="text-sm text-white/75">Client</dt>
              <dd className="mt-0.5 font-semibold text-white">{shift.client ?? '—'}</dd>
            </div>
            {shift.location && (
              <div className="sm:col-span-2">
                <dt className="text-sm text-white/75">Location</dt>
                <dd className="mt-0.5 font-semibold text-white">{shift.location}</dd>
              </div>
            )}
          </dl>

          {shift.notes && (
            <div>
              <p className="text-sm text-white/75">Notes</p>
              <p className="mt-1 rounded-panel border border-hairline bg-white/6 p-3 text-md text-white">
                {shift.notes}
              </p>
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}
