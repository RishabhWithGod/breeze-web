import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AlertTriangle, CalendarOff, Check, Undo2, Users, X } from 'lucide-react'
import { Alert, Button, Card, EmptyState, FilterTabs, Modal, StatusChip, TextArea } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { LeaveRequestRow, SharedPageProps } from '@/types'
import { formatCalendarDate, formatDate, LEAVE_STATUS_LABEL, leaveRange } from '@/utils'

export interface LeaveProps {
  /** Today in the company's own time zone (`YYYY-MM-DD`). */
  today: string
  pending: readonly LeaveRequestRow[]
  /** Approved, and still ahead or under way. */
  approved: readonly LeaveRequestRow[]
  history: readonly LeaveRequestRow[]
  /** For each waiting request, who else is already off on those days. */
  overlap: Record<string, readonly string[]>
}

type Tab = 'pending' | 'approved' | 'history'
type Decision = { readonly kind: 'approve' | 'reject' | 'revoke'; readonly leave: LeaveRequestRow }

const DECISION: Record<Decision['kind'], { title: string; confirm: string; required: boolean; hint: string }> = {
  approve: {
    title: 'Approve this leave?',
    confirm: 'Approve',
    required: false,
    hint: 'They are told it was approved, and show as unavailable on these days.',
  },
  reject: {
    title: 'Turn this request down?',
    confirm: 'Reject',
    required: true,
    hint: 'Tell them why, so they know what to change.',
  },
  revoke: {
    title: 'Cancel approved leave?',
    confirm: 'Cancel leave',
    required: false,
    hint: 'They are told it was cancelled, and are available again.',
  },
}

/**
 * Manage Leave — the time off the crew asked for in the field app.
 *
 * Approving makes the person unavailable on those days; Teams then shows how many days are left
 * until they are back, and that count drops by one every morning.
 */
export default function Leave({ today, pending, approved, history, overlap }: LeaveProps) {
  const { flash, errors } = usePage<SharedPageProps>().props
  const [tab, setTab] = useState<Tab>(pending.length > 0 ? 'pending' : 'approved')
  const [decision, setDecision] = useState<Decision | null>(null)

  const rows = tab === 'pending' ? pending : tab === 'approved' ? approved : history

  return (
    <PageTransition>
      <Head title="Manage Leave" />

      <PageHeader
        title="Manage Leave"
        subtitle="Time off the crew asked for in the field app. Approve it, or say why not."
        breadcrumbs={[
          { label: 'Clients', href: ROUTES.clients },
          { label: 'Teams', href: ROUTES.teams },
          { label: 'Leave' },
        ]}
      />

      {flash.success && (
        <Alert key={flash.success} tone="success" className="mb-5">
          {flash.success}
        </Alert>
      )}
      {flash.warning && (
        <Alert key={flash.warning} tone="warning" className="mb-5">
          {flash.warning}
        </Alert>
      )}
      {errors['leave'] && (
        <Alert key={errors['leave']} tone="danger" className="mb-5">
          {errors['leave']}
        </Alert>
      )}

      <FilterTabs
        className="mb-5"
        value={tab}
        onChange={setTab}
        options={[
          { label: 'Waiting', value: 'pending' },
          { label: 'Approved', value: 'approved' },
          { label: 'History', value: 'history' },
        ]}
        counts={{ pending: pending.length, approved: approved.length }}
      />

      {rows.length === 0 ? (
        <Card padding="lg">
          <EmptyState
            icon={CalendarOff}
            title={tab === 'pending' ? 'Nothing waiting' : tab === 'approved' ? 'No approved leave' : 'No history yet'}
            description={
              tab === 'pending'
                ? 'When someone asks for time off in the field app, it shows up here.'
                : tab === 'approved'
                  ? 'Leave you approve is listed here until it is over.'
                  : 'Leave that was turned down, cancelled or has finished is kept here.'
            }
          />
        </Card>
      ) : (
        <div className="space-y-3">
          {rows.map((leave) => (
            <LeaveCard
              key={leave.id}
              leave={leave}
              today={today}
              alsoOff={overlap[String(leave.id)] ?? []}
              onDecide={(kind) => setDecision({ kind, leave })}
            />
          ))}
        </div>
      )}

      {decision && <DecisionModal decision={decision} onClose={() => setDecision(null)} />}
    </PageTransition>
  )
}

function LeaveCard({
  leave,
  today,
  alsoOff,
  onDecide,
}: {
  leave: LeaveRequestRow
  today: string
  alsoOff: readonly string[]
  onDecide: (kind: Decision['kind']) => void
}) {
  const status = LEAVE_STATUS_LABEL[leave.status]
  const member = leave.member
  // Under way: the same count Teams shows, from the dates.
  const daysLeft = leave.underway ? Math.round((Date.parse(leave.endDate) - Date.parse(today)) / 86_400_000) + 1 : null
  const startsIn =
    leave.status === 'approved' && !leave.underway && leave.startDate > today
      ? Math.round((Date.parse(leave.startDate) - Date.parse(today)) / 86_400_000)
      : null

  return (
    <Card padding="md">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex min-w-0 items-start gap-3">
          <span className="grid size-10 shrink-0 place-items-center rounded-full bg-brand/15 text-xs font-semibold text-brand ring-1 ring-brand/30">
            {member?.initials ?? '?'}
          </span>
          <div className="min-w-0">
            <p className="font-semibold text-white">
              {member?.name ?? 'Former member'}
              {member?.inactive && <span className="ml-2 text-xs font-normal text-white/60">(inactive)</span>}
            </p>
            <p className="text-sm text-white/65">
              {[member?.roleLabel, member?.team].filter(Boolean).join(' · ') || '—'}
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <StatusChip pill tone={status.tone} label={status.label} />
          {leave.underway && daysLeft !== null && (
            <StatusChip
              pill
              tone="warning"
              label={daysLeft === 1 ? 'On leave · available tomorrow' : `On leave · available in ${daysLeft} days`}
            />
          )}
          {startsIn !== null && (
            <StatusChip pill tone="info" label={startsIn === 1 ? 'Starts tomorrow' : `Starts in ${startsIn} days`} />
          )}
        </div>
      </div>

      <dl className="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-3">
        <div>
          <dt className="text-xs text-white/55">Type</dt>
          <dd className="mt-0.5 font-medium text-white">{leave.typeLabel}</dd>
        </div>
        <div>
          <dt className="text-xs text-white/55">Days</dt>
          <dd className="mt-0.5 font-medium text-white">{leaveRange(leave.startDate, leave.endDate, leave.days)}</dd>
        </div>
        <div>
          <dt className="text-xs text-white/55">Asked</dt>
          <dd className="mt-0.5 text-white/85">
            {leave.requestedAt ? formatDate(leave.requestedAt, 'MMM d, h:mm a') : '—'}
          </dd>
        </div>
      </dl>

      {leave.reason && (
        <p className="mt-3 rounded-panel bg-white/5 px-3 py-2 text-sm text-white/85">“{leave.reason}”</p>
      )}

      {leave.status === 'pending' && (leave.shiftsAffected > 0 || alsoOff.length > 0) && (
        <div className="mt-3 space-y-1.5 text-sm">
          {leave.shiftsAffected > 0 && (
            <p className="flex items-center gap-2 text-status-warning">
              <AlertTriangle size={15} aria-hidden />
              {leave.shiftsAffected} scheduled {leave.shiftsAffected === 1 ? 'shift falls' : 'shifts fall'} on these
              days — they would need cover.
            </p>
          )}
          {alsoOff.length > 0 && (
            <p className="flex items-center gap-2 text-white/75">
              <Users size={15} aria-hidden />
              Also off then: {alsoOff.join(', ')}
            </p>
          )}
        </div>
      )}

      {leave.decisionNote && (
        <p className="mt-3 text-sm text-white/70">
          Manager’s note: <span className="text-white/90">{leave.decisionNote}</span>
          {leave.decidedAt && <span className="text-white/50"> · {formatDate(leave.decidedAt, 'MMM d')}</span>}
        </p>
      )}

      {(leave.status === 'pending' || leave.canRevoke) && (
        <div className="mt-4 flex flex-wrap justify-end gap-2">
          {leave.status === 'pending' ? (
            <>
              <Button variant="secondary" leftIcon={X} onClick={() => onDecide('reject')}>
                Reject
              </Button>
              <Button leftIcon={Check} onClick={() => onDecide('approve')}>
                Approve
              </Button>
            </>
          ) : (
            <Button variant="secondary" leftIcon={Undo2} onClick={() => onDecide('revoke')}>
              Cancel leave
            </Button>
          )}
        </div>
      )}
    </Card>
  )
}

function DecisionModal({ decision, onClose }: { decision: Decision; onClose: () => void }) {
  const copy = DECISION[decision.kind]
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const missing = copy.required && note.trim() === ''
  const url =
    decision.kind === 'approve'
      ? routeTo.leaveApprove(decision.leave.id)
      : decision.kind === 'reject'
        ? routeTo.leaveReject(decision.leave.id)
        : routeTo.leaveRevoke(decision.leave.id)

  const submit = () =>
    router.post(
      url,
      { note },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => {
          setBusy(false)
          onClose()
        },
      },
    )

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={copy.title}
      description={`${decision.leave.member?.name ?? 'Member'} — ${leaveRange(decision.leave.startDate, decision.leave.endDate, decision.leave.days)} (${formatCalendarDate(decision.leave.startDate, 'EEE')} start)`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Back
          </Button>
          <Button isLoading={busy} disabled={missing} onClick={submit}>
            {copy.confirm}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-white/70">{copy.hint}</p>
      <TextArea
        id="leave-note"
        label={copy.required ? 'Reason' : 'Note (optional)'}
        rows={3}
        maxLength={500}
        value={note}
        onChange={(event) => setNote(event.target.value)}
        addon={`${note.length}/500`}
      />
    </Modal>
  )
}

Leave.layout = appLayout
