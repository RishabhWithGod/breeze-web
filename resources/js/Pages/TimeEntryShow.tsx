import { useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import type { LucideIcon } from 'lucide-react'
import {
  ArrowLeft,
  Briefcase,
  Check,
  Clock,
  DollarSign,
  Hourglass,
  ListChecks,
  MapPin,
  Pencil,
  Send,
  Timer,
  Trash2,
  UserRound,
  Wallet,
  X,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  Modal,
  Table,
  TextArea,
} from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import { SessionStatus, clockTime, entrySessionStatus } from '@/components/timeTracking'
import { ApprovalHistoryPanel } from '@/components/review'
import {
  ROUTES,
  TIME_ENTRY_STATUS_LABEL,
  routeTo,
} from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  ApprovalHistoryEntry,
  SharedPageProps,
  TableColumn,
  TimeEntry,
  TimeEntryActionAbilities,
  TimeEntryActivity,
  TimeEntryCorrectionRef,
  TimeEntryEmployeeDetail,
  TimeEntryJobDetail,
  TimeEntryJobTimeSummary,
  TimeEntryRelatedRow,
  TimeEntryTaskDetail,
} from '@/types'
import { cn, formatCalendarDate, formatCurrency, formatDate, formatHours } from '@/utils'

export interface TimeEntryShowProps {
  entry: TimeEntry
  activities: readonly TimeEntryActivity[]
  employee: TimeEntryEmployeeDetail
  job: TimeEntryJobDetail | null
  task: TimeEntryTaskDetail | null
  corrects: TimeEntryCorrectionRef | null
  corrections: readonly TimeEntryCorrectionRef[]
  jobTimeSummary: TimeEntryJobTimeSummary | null
  relatedEntries: readonly TimeEntryRelatedRow[]
  can: TimeEntryActionAbilities
}

const taskTypeLabel = (value: string) => value.replace(/-/g, ' ')

/**
 * Time Entry detail — the electrician, the job and task it was logged
 * against, the time itself, and the full approval trail. Laid out like
 * Estimate Detail (summary cards, then a prominent figures card, then history
 * and totals) so it reads as the same application, not a bolted-on screen.
 */
export default function TimeEntryShow({
  entry,
  activities,
  employee,
  job,
  task,
  corrects,
  corrections,
  jobTimeSummary,
  relatedEntries,
  can,
}: TimeEntryShowProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [rejectReason, setRejectReason] = useState('')
  const rejectDialog = useDisclosure()
  const deleteDialog = useDisclosure()

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  const history: readonly ApprovalHistoryEntry[] = activities.map((activity) => ({
    id: activity.id,
    action: activity.type,
    subject: null,
    description: activity.description,
    from: null,
    to: null,
    actor: activity.actor,
    timestamp: activity.timestamp,
  }))

  const submit = () => router.post(routeTo.timeEntrySubmit(entry.id), {}, { preserveScroll: true })
  const approve = () => router.post(routeTo.timeEntryApprove(entry.id), {}, { preserveScroll: true })

  const confirmReject = () => {
    if (rejectReason.trim().length < 3) return

    router.post(
      routeTo.timeEntryReject(entry.id),
      { reason: rejectReason },
      { preserveScroll: true, onSuccess: () => rejectDialog.close() },
    )
  }

  const confirmDelete = () => {
    router.delete(routeTo.timeEntry(entry.id), {
      onSuccess: () => router.visit(ROUTES.timeEntries),
    })
    deleteDialog.close()
  }

  const relatedColumns: TableColumn<TimeEntryRelatedRow>[] = [
    { key: 'date', header: 'Date', render: (row) => formatDate(row.date) },
    { key: 'employee', header: 'Employee', render: (row) => row.employee },
    { key: 'task', header: 'Task', render: (row) => row.task },
    { key: 'start', header: 'Check-in', render: (row) => clockTime(row.startTime) },
    { key: 'end', header: 'Checkout', render: (row) => clockTime(row.endTime) },
    { key: 'hours', header: 'Hours', align: 'right', render: (row) => formatHours(row.hours) },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <SessionStatus status={entrySessionStatus(row)} />,
    },
    {
      key: 'actions',
      header: 'Actions',
      render: (row) => (
        <ButtonLink href={routeTo.timeEntry(row.id)} size="sm" variant="secondary">
          View
        </ButtonLink>
      ),
    },
  ]

  const sessionStatus = entrySessionStatus(entry)
  const cost = can.viewJobCosts && entry.laborCost !== null

  return (
    <PageTransition>
      <Head title={`Time Entry #${entry.id}`} />

      {/* ==================================================== Header ========= */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <Link
            href={ROUTES.timeTracking}
            className="inline-flex items-center gap-2 text-md font-medium text-white transition-colors hover:text-brand"
          >
            <ArrowLeft size={17} aria-hidden />
            Time Tracking
          </Link>

          <div className="mt-3 flex flex-wrap items-center gap-3">
            <h1 className="text-3xl font-bold text-white sm:text-4xl">Time Entry #{entry.id}</h1>
            <SessionStatus status={sessionStatus} />
            {entry.billable && (
              <Badge tone="success" icon={DollarSign}>
                Billable
              </Badge>
            )}
            {entry.isCorrection && <Badge tone="warning">Correction</Badge>}
          </div>

          <p className="mt-2 text-md text-white/85">
            Logged {formatCalendarDate(entry.date, 'EEEE, MMMM d, yyyy')}
            {entry.submittedAt ? ` · Submitted ${formatCalendarDate(entry.submittedAt)}` : ''}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          {can.approve && (
            <Button leftIcon={Check} onClick={approve}>
              Approve
            </Button>
          )}
          {can.reject && (
            <Button leftIcon={X} variant="danger" onClick={rejectDialog.open}>
              Reject
            </Button>
          )}
          {can.submit && (
            <Button leftIcon={Send} onClick={submit}>
              Submit
            </Button>
          )}
          {can.update && (
            <ButtonLink href={routeTo.timeEntryEdit(entry.id)} variant="white" leftIcon={Pencil}>
              Edit
            </ButtonLink>
          )}
          {can.delete && (
            <Button variant="secondary" leftIcon={Trash2} onClick={deleteDialog.open}>
              Delete
            </Button>
          )}
        </div>
      </div>

      <AnimatePresence initial={false}>
        {notice && (
          <Alert
            key={notice}
            tone={flash.warning ? 'warning' : 'success'}
            className="mt-4"
            onDismiss={() => setDismissed(notice)}
          >
            {notice}
          </Alert>
        )}
      </AnimatePresence>

      {/* A locked entry that's since been corrected, or a draft that corrects
          one, needs a way to reach the other side. */}
      {corrects && (
        <Alert tone="warning" className="mt-4">
          This entry corrects{' '}
          <Link href={routeTo.timeEntry(corrects.id)} className="font-medium text-brand hover:underline">
            entry #{corrects.id}
          </Link>{' '}
          ({formatDate(corrects.date)}, {formatHours(corrects.hours)}, now {TIME_ENTRY_STATUS_LABEL[corrects.status]}).
        </Alert>
      )}
      {corrections.length > 0 && (
        <Alert tone="warning" className="mt-4">
          {corrections.length === 1 ? 'A correction has' : `${corrections.length} corrections have`} been filed
          for this entry:{' '}
          {corrections.map((correction, index) => (
            <span key={correction.id}>
              {index > 0 && ', '}
              <Link href={routeTo.timeEntry(correction.id)} className="font-medium text-brand hover:underline">
                entry #{correction.id}
              </Link>{' '}
              ({TIME_ENTRY_STATUS_LABEL[correction.status]})
            </span>
          ))}
          .
        </Alert>
      )}

      {/* Who, where, what — the facts the rest of the screen hangs on. */}
      <dl className="mt-5 flex flex-wrap gap-x-10 gap-y-4">
        <MetaItem icon={UserRound} label="Employee" value={employee.role ? `${employee.name} · ${employee.role}` : employee.name} />
        <MetaItem icon={Briefcase} label="Job" value={job?.name ?? 'No job'} />
        <MetaItem icon={ListChecks} label="Task" value={task?.title ?? entry.taskLabel ?? '—'} />
        <MetaItem icon={MapPin} label="Location" value={job?.location ?? '—'} />
      </dl>

      {/* ================================================= Summary cards ===== */}
      <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)]">
        <StatCard icon={Clock} label="Total Hours" value={formatHours(entry.hours)} />
        <StatCard
          icon={Timer}
          label="Check-in → Checkout"
          value={`${clockTime(entry.startTime)} – ${clockTime(entry.endTime)}`}
          nowrap
          note={entry.breakMinutes > 0 ? `${entry.breakMinutes} min break` : 'No break'}
        />
        <StatCard
          icon={Hourglass}
          label="Regular / Overtime"
          value={`${formatHours(entry.regularHours)} / ${formatHours(entry.overtimeHours)}`}
        />
        <StatCard
          icon={Wallet}
          label={cost ? 'Labor Cost' : 'Billable'}
          value={cost && entry.laborCost !== null ? formatCurrency(entry.laborCost, 2) : entry.billable ? 'Yes' : 'No'}
          {...(cost && entry.billableAmount !== null ? { note: `${formatCurrency(entry.billableAmount, 2)} billable` } : {})}
        />
      </div>

      <div className="mt-5 grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="min-w-0 space-y-5">
          <Card padding="md">
            <h2 className="text-lg font-semibold text-white">Time Details</h2>
            <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-3">
              <Field label="Date" value={formatDate(entry.date)} />
              <Field label="Check-in" value={clockTime(entry.startTime)} />
              <Field label="Checkout" value={clockTime(entry.endTime)} />
              <Field label="Break" value={`${entry.breakMinutes} min`} />
              <Field label="Total Hours" value={formatHours(entry.hours)} strong />
              <Field label="Source" value={entry.source === 'timer' ? 'Timer' : 'Manual entry'} />
            </dl>
          </Card>

          <Card padding="md">
            <h2 className="text-lg font-semibold text-white">Task</h2>
            {task ? (
              <>
                <dl className="mt-4 grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                  <Field label="Task" value={task.title} />
                  <Field label="Type" value={task.category ? taskTypeLabel(task.category) : '—'} />
                  <div>
                    <dt className="text-2xs tracking-wide text-white/80 uppercase">Status</dt>
                    <dd className="mt-1">
                      <Badge>{taskTypeLabel(task.status)}</Badge>
                    </dd>
                  </div>
                  <Field label="Priority" value={task.priority ? taskTypeLabel(task.priority) : '—'} />
                  <Field
                    label="Scheduled"
                    value={task.startsOn ? `${formatDate(task.startsOn)}${task.endsOn ? ` → ${formatDate(task.endsOn)}` : ''}` : '—'}
                  />
                  <Field
                    label="Task Hours"
                    value={`${task.estimatedHours !== null ? formatHours(task.estimatedHours) : '—'} est. · ${formatHours(task.actualHours)} actual`}
                  />
                  <div className="sm:col-span-2 lg:col-span-3">
                    <dt className="text-2xs tracking-wide text-white/80 uppercase">Assigned</dt>
                    <dd className="mt-1 text-md text-white">
                      {task.assignees.length > 0 ? task.assignees.map((a) => a.name).join(', ') : 'Nobody assigned yet'}
                    </dd>
                  </div>
                </dl>
                {task.description && (
                  <p className="mt-4 border-t border-hairline pt-4 text-md text-white/90">{task.description}</p>
                )}
              </>
            ) : (
              <p className="mt-3 text-md text-white/75">
                {entry.taskLabel
                  ? `Not linked to a scheduled task — logged as “${entry.taskLabel}”.`
                  : entry.source === 'timer'
                    ? 'Logged with the job timer, which tracks time against the whole job rather than one task.'
                    : 'No task recorded for this entry.'}
              </p>
            )}
          </Card>

          <Card padding="md">
            <h2 className="text-lg font-semibold text-white">Work Performed</h2>
            <p className={cn('mt-3 text-md', entry.description ? 'text-white/90' : 'text-white/70')}>
              {entry.description ?? 'No description was entered for this entry.'}
            </p>
          </Card>

          <Card padding="md">
            <h2 className="text-lg font-semibold text-white">Activity & Approval History</h2>
            <p className="mt-0.5 text-xs text-white/70">Every status change, in order</p>
            <div className="mt-4">
              <ApprovalHistoryPanel entries={history} />
            </div>
            {entry.rejectionReason && (
              <div className="mt-4 rounded-panel border border-status-danger/40 bg-status-danger/10 p-4">
                <p className="text-2xs tracking-wide text-red-300 uppercase">Rejection reason</p>
                <p className="mt-1 text-md text-white">{entry.rejectionReason}</p>
              </div>
            )}
          </Card>
        </div>

        <div className="min-w-0 space-y-5">
          <Card padding="md">
            <div className="flex items-center justify-between gap-3">
              <h2 className="text-lg font-semibold text-white">Job</h2>
              {job && (
                <ButtonLink href={routeTo.job(job.id)} size="sm" variant="secondary">
                  Open job
                </ButtonLink>
              )}
            </div>
            {job ? (
              <dl className="mt-4 space-y-4">
                <Field label="Job" value={`${job.name} (#${job.id})`} />
                <Field label="Client" value={job.client ?? '—'} />
                <Field label="Foreman" value={job.foreman ?? 'Unassigned'} />
                <Field label="Type" value={job.jobType ? taskTypeLabel(job.jobType) : '—'} />
                <Field
                  label="Schedule"
                  value={job.startDate ? `${formatDate(job.startDate)} → ${job.endDate ? formatDate(job.endDate) : 'open'}` : '—'}
                />
                <div>
                  <dt className="text-2xs tracking-wide text-white/80 uppercase">Status</dt>
                  <dd className="mt-1">
                    <Badge>{job.status}</Badge>
                  </dd>
                </div>
              </dl>
            ) : (
              <p className="mt-3 text-md text-white/75">No job on record for this entry.</p>
            )}
          </Card>

          <Card padding="md">
            <h2 className="text-lg font-semibold text-white">Employee</h2>
            <div className="mt-4 flex items-center gap-4">
              <span className="grid size-12 shrink-0 place-items-center rounded-full bg-brand/15 text-md font-semibold text-brand ring-1 ring-brand/30">
                {employee.initials ?? employee.name.slice(0, 2).toUpperCase()}
              </span>
              <div className="min-w-0">
                <p className="truncate text-md font-semibold text-white">{employee.name}</p>
                {employee.role && <p className="text-sm text-white/75">{employee.role}</p>}
                {employee.email && <p className="truncate text-xs text-white/65">{employee.email}</p>}
              </div>
            </div>
            {(employee.costRate !== null || employee.billableRate !== null) && (
              <dl className="mt-4 grid grid-cols-2 gap-4 border-t border-hairline pt-4">
                {employee.costRate !== null && (
                  <Field label="Cost Rate" value={`${formatCurrency(employee.costRate, 2)}/hr`} />
                )}
                {employee.billableRate !== null && (
                  <Field label="Billable Rate" value={`${formatCurrency(employee.billableRate, 2)}/hr`} />
                )}
              </dl>
            )}
          </Card>

          {cost && entry.laborCost !== null && (
            <Card padding="md">
              <h2 className="text-lg font-semibold text-white">Labor Cost</h2>
              <dl className="mt-4 flex flex-col gap-2">
                <TotalRow label="Hours" value={formatHours(entry.hours)} />
                {entry.costRate !== null && <TotalRow label="Cost Rate" value={`${formatCurrency(entry.costRate, 2)}/hr`} />}
                <TotalRow label="Labor Cost" value={formatCurrency(entry.laborCost, 2)} strong />
                {entry.billableRate !== null && (
                  <TotalRow label="Billable Rate" value={`${formatCurrency(entry.billableRate, 2)}/hr`} />
                )}
                {entry.billableAmount !== null && (
                  <TotalRow label="Billable Amount" value={formatCurrency(entry.billableAmount, 2)} strong />
                )}
              </dl>
            </Card>
          )}

          {jobTimeSummary && (
            <Card padding="md">
              <h2 className="text-lg font-semibold text-white">Job Time</h2>
              <dl className="mt-4 flex flex-col gap-2">
                <TotalRow label="This Entry" value={formatHours(jobTimeSummary.thisEntryHours)} />
                <TotalRow label="Approved Job Hours" value={formatHours(jobTimeSummary.approvedJobHours)} />
                <TotalRow label="This Employee (Job)" value={formatHours(jobTimeSummary.employeeJobHours)} strong />
                <TotalRow label="Billable" value={formatHours(jobTimeSummary.employeeJobBillableHours)} />
              </dl>
            </Card>
          )}
        </div>
      </div>

      {/* ================================================ Related entries ====== */}
      {relatedEntries.length > 0 && (
        <Card padding="md" className="mt-5">
          <h2 className="text-lg font-semibold text-white">Related Entries</h2>
          <p className="mt-0.5 text-xs text-white/70">Other time logged on this job</p>
          <div className="mt-3 overflow-x-auto">
            <Table
              dense
              variant="lined"
              headerVariant="plain"
              className="min-w-3xl text-sm [&_th]:text-sm [&_td]:text-sm"
              columns={relatedColumns}
              rows={relatedEntries}
              getRowId={(row) => row.id}
              caption="Related time entries"
            />
          </div>
        </Card>
      )}

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title="Delete this time entry?"
        description="This cannot be undone."
        confirmLabel="Delete entry"
        confirmVariant="danger"
        onConfirm={confirmDelete}
        onCancel={deleteDialog.close}
      />

      <Modal
        isOpen={rejectDialog.isOpen}
        onClose={rejectDialog.close}
        title="Reject time entry"
        description="Say why, so the electrician knows what to fix and can resubmit."
        footer={
          <>
            <Button variant="ghost" onClick={rejectDialog.close}>
              Cancel
            </Button>
            <Button
              variant="danger"
              leftIcon={X}
              disabled={rejectReason.trim().length < 3}
              onClick={confirmReject}
            >
              Reject
            </Button>
          </>
        }
      >
        <TextArea
          id="reject-reason"
          label="Reason"
          rows={3}
          value={rejectReason}
          onChange={(event) => setRejectReason(event.target.value)}
          placeholder="e.g. Hours don't match the schedule for this day."
          autoFocus
        />
      </Modal>
    </PageTransition>
  )
}

function Field({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="min-w-0">
      <dt className="text-2xs tracking-wide text-white/80 uppercase">{label}</dt>
      <dd className={strong ? 'mt-1 text-lg font-semibold text-white' : 'mt-1 truncate text-md text-white'} title={value}>
        {value}
      </dd>
    </div>
  )
}

function TotalRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={strong ? 'flex items-center justify-between gap-3 border-t border-hairline pt-2' : 'flex items-center justify-between gap-3'}>
      <dt className={strong ? 'text-md font-semibold text-white' : 'text-md text-white/90'}>{label}</dt>
      <dd className={strong ? 'text-lg font-bold text-white' : 'text-md font-medium text-white/90'}>{value}</dd>
    </div>
  )
}

function MetaItem({ icon: Icon, label, value }: { icon: LucideIcon; label: string; value: string }) {
  return (
    <div className="flex items-center gap-3">
      <Icon size={22} aria-hidden className="shrink-0 text-white/85" />
      <div className="min-w-0">
        <dt className="text-xs text-white/70">{label}</dt>
        <dd className="text-md font-medium text-white">{value}</dd>
      </div>
    </div>
  )
}

function StatCard({
  icon: Icon,
  label,
  value,
  note,
  nowrap = false,
}: {
  icon: LucideIcon
  label: string
  value: string
  note?: string
  /** Keeps the value on one line — a time range should read as one thing, and is never cut. */
  nowrap?: boolean
}) {
  return (
    <Card padding="md" className="flex items-center gap-4">
      <span className="grid size-12 shrink-0 place-items-center rounded-panel bg-ocean-600/60 text-brand ring-1 ring-brand/25">
        <Icon size={22} aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-sm text-white/75">{label}</p>
        <p className={cn('text-xl leading-tight font-bold text-white', nowrap && 'whitespace-nowrap')}>{value}</p>
        {note && <p className="text-xs text-white/65">{note}</p>}
      </div>
    </Card>
  )
}

TimeEntryShow.layout = appLayout
