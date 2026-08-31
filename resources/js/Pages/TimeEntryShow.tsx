import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  ArrowLeft,
  Check,
  DollarSign,
  Pencil,
  Send,
  Trash2,
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
  SectionHeading,
  StatusChip,
  Table,
  TextArea,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ApprovalHistoryPanel } from '@/components/review'
import {
  ROUTES,
  TIME_ENTRY_STATUS_LABEL,
  TIME_ENTRY_STATUS_TONE,
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
  TimeEntryEmployeeDetail,
  TimeEntryJobDetail,
  TimeEntryJobTimeSummary,
  TimeEntryRelatedRow,
  TimeEntryTaskDetail,
} from '@/types'
import { formatCurrency, formatDate, formatHours } from '@/utils'

export interface TimeEntryShowProps {
  entry: TimeEntry
  activities: readonly TimeEntryActivity[]
  employee: TimeEntryEmployeeDetail
  job: TimeEntryJobDetail | null
  task: TimeEntryTaskDetail | null
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
    { key: 'start', header: 'Start', render: (row) => row.startTime?.slice(0, 5) ?? '—' },
    { key: 'end', header: 'End', render: (row) => row.endTime?.slice(0, 5) ?? '—' },
    { key: 'hours', header: 'Hours', align: 'right', render: (row) => formatHours(row.hours) },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <StatusChip tone={TIME_ENTRY_STATUS_TONE[row.status]} label={TIME_ENTRY_STATUS_LABEL[row.status]} />
      ),
    },
    {
      key: 'actions',
      header: '',
      render: (row) => (
        <ButtonLink href={routeTo.timeEntry(row.id)} size="sm" variant="ghost">
          View
        </ButtonLink>
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title={`Time Entry #${entry.id}`} />

      <PageHeader
        title={`Time Entry #${entry.id}`}
        subtitle={`${employee.name}${job ? ` · ${job.name}` : ''}`}
        breadcrumbs={[
          { label: 'Time Tracking', href: ROUTES.timeTracking },
          { label: `#${entry.id}` },
        ]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ButtonLink href={ROUTES.timeEntries} variant="secondary" size="sm" leftIcon={ArrowLeft}>
              Back to Entries
            </ButtonLink>
            {can.update && (
              <ButtonLink href={routeTo.timeEntryEdit(entry.id)} size="sm" leftIcon={Pencil}>
                Edit
              </ButtonLink>
            )}
            {can.submit && (
              <Button size="sm" leftIcon={Send} onClick={submit}>
                Submit
              </Button>
            )}
            {can.delete && (
              <Button size="sm" variant="danger" leftIcon={Trash2} onClick={deleteDialog.open}>
                Delete
              </Button>
            )}
          </div>
        }
      />

      <AnimatePresence initial={false}>
        {notice && (
          <Alert
            key={notice}
            tone={flash.warning ? 'warning' : 'success'}
            className="mb-6"
            onDismiss={() => setDismissed(notice)}
          >
            {notice}
          </Alert>
        )}
      </AnimatePresence>

      <div className="mb-6 flex flex-wrap items-center gap-3">
        <StatusChip tone={TIME_ENTRY_STATUS_TONE[entry.status]} label={TIME_ENTRY_STATUS_LABEL[entry.status]} />
        {entry.billable && <Badge tone="success" icon={DollarSign}>Billable</Badge>}
        {entry.isCorrection && <Badge tone="warning">Correction</Badge>}
        <span className="text-sm text-white/70">
          Logged {formatDate(entry.date)} · Last updated {formatDate(entry.submittedAt ?? entry.date)}
        </span>
      </div>

      {/* ============================================ Summary cards =========== */}
      <div className="grid gap-6 xl:grid-cols-2">
        <Card padding="lg">
          <SectionHeading as="h3" title="Electrician Information" />
          <div className="flex items-start gap-4">
            <span className="grid size-14 shrink-0 place-items-center rounded-full bg-ocean-800 text-md font-semibold text-white ring-1 ring-steel-600">
              {employee.initials ?? employee.name.slice(0, 2).toUpperCase()}
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-lg font-semibold text-white">{employee.name}</p>
              {employee.role && <p className="text-sm text-white/80">{employee.role}</p>}
              {employee.email && <p className="mt-1 text-sm text-white/70">{employee.email}</p>}
            </div>
          </div>

          {(employee.costRate !== null || employee.billableRate !== null) && (
            <dl className="mt-5 grid grid-cols-2 gap-4 border-t border-hairline pt-4">
              {employee.costRate !== null && (
                <div>
                  <dt className="text-2xs tracking-wide text-white/80 uppercase">Cost Rate</dt>
                  <dd className="mt-1 text-md text-white">{formatCurrency(employee.costRate, 2)}/hr</dd>
                </div>
              )}
              {employee.billableRate !== null && (
                <div>
                  <dt className="text-2xs tracking-wide text-white/80 uppercase">Billable Rate</dt>
                  <dd className="mt-1 text-md text-white">{formatCurrency(employee.billableRate, 2)}/hr</dd>
                </div>
              )}
            </dl>
          )}
        </Card>

        <Card padding="lg">
          <SectionHeading
            as="h3"
            title="Job Information"
            actions={job && <ButtonLink href={routeTo.job(job.id)} size="sm" variant="secondary">Open job</ButtonLink>}
          />
          {job ? (
            <dl className="grid gap-4 sm:grid-cols-2">
              <Field label="Job" value={`${job.name} (#${job.id})`} />
              <Field label="Client" value={job.client ?? '—'} />
              <Field label="Location" value={job.location ?? '—'} />
              <Field label="Job Type" value={job.jobType ? taskTypeLabel(job.jobType) : '—'} />
              <Field label="Foreman" value={job.foreman ?? 'Unassigned'} />
              <div>
                <dt className="text-2xs tracking-wide text-white/80 uppercase">Status</dt>
                <dd className="mt-1"><Badge>{job.status}</Badge></dd>
              </div>
              <Field
                label="Schedule"
                value={job.startDate ? `${formatDate(job.startDate)} → ${job.endDate ? formatDate(job.endDate) : 'open'}` : '—'}
              />
            </dl>
          ) : (
            <p className="text-md text-white/70">No job on record for this entry.</p>
          )}
        </Card>
      </div>

      {/* ================================================ Task details ========= */}
      <Card padding="lg" className="mt-6">
        <SectionHeading as="h3" title="Task Details" />
        {task ? (
          <>
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Field label="Task" value={task.title} />
              <Field label="Task Type" value={task.category ? taskTypeLabel(task.category) : '—'} />
              <div>
                <dt className="text-2xs tracking-wide text-white/80 uppercase">Status</dt>
                <dd className="mt-1"><Badge>{taskTypeLabel(task.status)}</Badge></dd>
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
              <div className="sm:col-span-2 lg:col-span-2">
                <dt className="text-2xs tracking-wide text-white/80 uppercase">Assigned</dt>
                <dd className="mt-1 text-md text-white">
                  {task.assignees.length > 0
                    ? task.assignees.map((a) => a.name).join(', ')
                    : 'Nobody assigned yet'}
                </dd>
              </div>
            </dl>
            {task.description && (
              <p className="mt-4 border-t border-hairline pt-4 text-md text-white/90">{task.description}</p>
            )}
          </>
        ) : (
          <p className="text-md text-white/70">
            {entry.taskLabel ? `Not linked to a scheduled task — logged as "${entry.taskLabel}".` : 'No task recorded for this entry.'}
          </p>
        )}
      </Card>

      {/* ================================================= Time details ======= */}
      <Card padding="lg" className="mt-6">
        <SectionHeading as="h3" title="Time Details" subtitle="Calculated by the server — never recomputed here" />
        <dl className="grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-6">
          <Field label="Date" value={formatDate(entry.date)} />
          <Field label="Start Time" value={entry.startTime?.slice(0, 5) ?? '—'} />
          <Field label="End Time" value={entry.endTime?.slice(0, 5) ?? '—'} />
          <Field label="Break" value={`${entry.breakMinutes} min`} />
          <Field label="Total Hours" value={formatHours(entry.hours)} strong />
          <Field label="Overtime" value={formatHours(entry.overtimeHours)} />
          <Field label="Regular Hours" value={formatHours(entry.regularHours)} />
          <Field label="Billable" value={entry.billable ? 'Yes' : 'No'} />
          <Field label="Source" value={entry.source === 'timer' ? 'Timer' : 'Manual'} />
        </dl>
      </Card>

      {/* ============================================= Work description ======= */}
      <Card padding="lg" className="mt-6">
        <SectionHeading as="h3" title="Work Performed" />
        {entry.description ? (
          <p className="text-md text-white/90">{entry.description}</p>
        ) : (
          <p className="text-md text-white/70">No description was entered for this entry.</p>
        )}
      </Card>

      {/* ======================================= History + cost/job summary ==== */}
      <div className="mt-6 grid gap-6 xl:grid-cols-[1.6fr_1fr]">
        <Card padding="lg">
          <SectionHeading as="h3" title="Activity & Approval History" subtitle="Every status change, in order" />
          <ApprovalHistoryPanel entries={history} />
          {entry.rejectionReason && (
            <div className="mt-4 rounded-panel border border-status-danger/40 bg-status-danger/10 p-4">
              <p className="text-2xs tracking-wide text-red-300 uppercase">Rejection reason</p>
              <p className="mt-1 text-md text-white">{entry.rejectionReason}</p>
            </div>
          )}
        </Card>

        <div className="flex min-w-0 flex-col gap-6">
          {can.viewJobCosts && entry.laborCost !== null && (
            <Card padding="lg">
              <SectionHeading as="h3" title="Labor Cost Summary" />
              <dl className="flex flex-col gap-2">
                <TotalRow label="Hours" value={formatHours(entry.hours)} />
                {entry.costRate !== null && <TotalRow label="Cost Rate" value={`${formatCurrency(entry.costRate, 2)}/hr`} />}
                <TotalRow label="Labor Cost" value={formatCurrency(entry.laborCost, 2)} strong />
                {entry.billableRate !== null && <TotalRow label="Billable Rate" value={`${formatCurrency(entry.billableRate, 2)}/hr`} />}
                {entry.billableAmount !== null && (
                  <TotalRow label="Billable Amount" value={formatCurrency(entry.billableAmount, 2)} strong />
                )}
              </dl>
            </Card>
          )}

          {jobTimeSummary && (
            <Card padding="lg">
              <SectionHeading as="h3" title="Job Time Summary" />
              <dl className="flex flex-col gap-2">
                <TotalRow label="This Entry" value={formatHours(jobTimeSummary.thisEntryHours)} />
                <TotalRow label="Approved Job Hours" value={formatHours(jobTimeSummary.approvedJobHours)} />
                <TotalRow label="This Electrician (Job)" value={formatHours(jobTimeSummary.employeeJobHours)} strong />
                <TotalRow label="Billable" value={formatHours(jobTimeSummary.employeeJobBillableHours)} />
              </dl>
            </Card>
          )}

          <Card padding="lg">
            <SectionHeading as="h3" title="Decision" />
            <div className="flex flex-wrap gap-3">
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
              {!can.approve && !can.reject && (
                <p className="text-md text-white/70">No approval action is available for this entry right now.</p>
              )}
            </div>
          </Card>
        </div>
      </div>

      {/* ================================================ Related entries ====== */}
      {relatedEntries.length > 0 && (
        <Card padding="lg" className="mt-6">
          <SectionHeading as="h3" title="Related Entries" subtitle="Other time logged on this job" />
          <Table
            dense
            variant="lined"
            headerVariant="plain"
            columns={relatedColumns}
            rows={relatedEntries}
            getRowId={(row) => row.id}
            caption="Related time entries"
          />
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

TimeEntryShow.layout = appLayout
