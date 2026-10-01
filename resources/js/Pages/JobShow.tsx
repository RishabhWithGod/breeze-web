import { useCallback, useMemo, useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import type { LucideIcon } from 'lucide-react'
import {
  Activity,
  Archive,
  FilePen,
  ArrowLeft,
  BarChart3,
  Building2,
  Calculator,
  CalendarDays,
  ChevronDown,
  ClipboardList,
  Clock,
  Database,
  FileText,
  FileUp,
  FolderKanban,
  ListChecks,
  MapPin,
  NotebookPen,
  PencilLine,
  Plus,
  Receipt,
  Trash2,
  Users,
} from 'lucide-react'
import {
  Alert,
  Badge,
  ButtonLink,
  Card,
  ConfirmDialog,
  IconButton,
  MoreMenu,
  SectionHeading,
} from '@/components/common'
import {
  JobAttachmentsPanel,
  JobEstimatesPanel,
  JobNotesPanel,
  JobTakeoffPanel,
  JobTaskFieldNotesPanel,
  JobTasksPanel,
} from '@/components/jobs'
import { ChangeOrderSourcePill, ChangeOrderStatusPill } from '@/components/changeOrders/ChangeOrderPills'
import { appLayout, PageTransition } from '@/components/layout'
import { JOB_STATUS_OPTIONS, routeTo } from '@/constants'
import type { JobOrigin } from '@/constants'
import { useDisclosure, useEchoConnectionState, usePrivateChannel, usePermissions } from '@/hooks'
import type {
  ChangeOrderSource,
  ChangeOrderStatus,
  JobCostRow,
  JobDetail,
  JobStatus,
  SharedPageProps,
  Tone,
} from '@/types'
import {
  JOB_STATUS_TONE,
  TONE_DOT_CLASS,
  cn,
  formatCalendarDate,
  formatCurrency,
  formatHours,
  formatRelative,
}  from '@/utils'

export interface JobShowProps {
  job: JobDetail
  canViewTimeCosts: boolean
  jobCosting: JobCostRow
  /** False for anyone who cannot plan work — the tasks are still readable. */
  canPlanWork: boolean
  /**
   * False for anyone who cannot manage apprentice assignments — read/manage
   * only here. Making an assignment is a foreman's call from the mobile
   * app's Job Detail screen, not exposed on the web.
   */
  canManageApprentices: boolean
  /** Role-only — whether this user may raise an invoice at all. Already-invoiced is `job.hasInvoice`. */
  canCreateInvoice: boolean
  /** This job's change orders — null for anyone who has no change orders screen. */
  changeOrders: {
    canRaise: boolean
    items: readonly {
      readonly id: number
      readonly label: string
      readonly description: string
      readonly source: ChangeOrderSource
      readonly status: ChangeOrderStatus
      readonly amount: number
    }[]
  } | null
  apprenticeAssignments: readonly {
    readonly id: number
    readonly journeymanId: number
    readonly journeymanName: string
    readonly apprenticeId: number
    readonly apprenticeName: string
  }[]
  /**
   * Where Back returns to, resolved by the server from the link that got here.
   * A job is opened from the jobs list, from Scheduling and from the task list,
   * and Back has to undo the step that was actually taken.
   */
  back: { label: string; url: string }
  /** The same trail as a bare name, so Edit can carry it on. */
  from: JobOrigin | null
}

/**
 * Job detail — the hub of the job management module.
 *
 * Every panel writes through its own controller and the page reloads with the
 * updated relationships, so what is on screen always matches the database.
 */
export default function JobShow({
  job,
  jobCosting,
  canPlanWork,
  canManageApprentices,
  canCreateInvoice,
  changeOrders,
  apprenticeAssignments,
  back,
  from,
}: JobShowProps) {
  const { can: permitted } = usePermissions()
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const deleteDialog = useDisclosure()

  // Once a job is completed, nothing about it changes again — see
  // `Job::isLocked()`. Every write this screen offers is guarded the same
  // way server-side; this just keeps the screen from offering what the
  // server would refuse.
  const isLocked = job.isLocked

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  const changeStatus = (status: JobStatus) => {
    router.post(routeTo.jobStatus(job.id), { status }, { preserveScroll: true })
  }

  const removeApprentice = (assignmentId: number) => {
    router.delete(routeTo.jobApprentice(job.id, assignmentId), { preserveScroll: true })
  }

  // Status, staffing and cost activity all live inside the `job` and
  // `jobCosting` props — one small partial reload covers whichever of those a
  // realtime event on this job just changed, without touching scroll
  // position, open modals, or the delete-confirmation dialog.
  const resync = useCallback(() => {
    router.reload({ only: ['job', 'jobCosting'] })
  }, [])

  usePrivateChannel(`job.${job.id}`, {
    'job.status-changed': resync,
    'job.assignment-changed': resync,
    'schedule.changed': resync,
    'time-entry.logged': resync,
    'time-entry.approved': resync,
    'time-entry.rejected': resync,
  })

  useEchoConnectionState(resync)

  const [tab, setTab] = useState<JobTab>('overview')

  // Progress is what the job's own task list says: done over all.
  const tasksDone = job.tasks.filter((task) => task.status === 'completed').length
  const tasksTotal = job.tasks.length
  const progress = tasksTotal > 0 ? Math.round((tasksDone / tasksTotal) * 100) : 0
  const estimatedHours = job.tasks.reduce((sum, task) => sum + (task.estimatedHours ?? 0), 0)
  const actualHours = job.tasks.reduce((sum, task) => sum + (task.actualHours ?? 0), 0)

  // Money figures are redacted to null for anyone without cost access — shown as a dash.
  const money = (value: number | null | undefined) =>
    value === null || value === undefined ? '—' : formatCurrency(value, 0)
  const jobValue = jobCosting.revenue ?? job.budget
  const billedPct =
    jobCosting.billed !== null && jobValue ? ((jobCosting.billed / jobValue) * 100).toFixed(1) : null

  // Everyone on the job's tasks, each person once, with the tasks they are on.
  const taskCrew = useMemo(() => {
    const byPerson = new Map<
      string,
      { name: string; initials: string | null; roles: Set<string>; tasks: { id: number; title: string }[] }
    >()

    for (const task of job.tasks) {
      for (const person of task.crew) {
        const key = person.name.trim().toLowerCase()
        const entry =
          byPerson.get(key) ??
          { name: person.name, initials: person.initials, roles: new Set<string>(), tasks: [] }

        entry.roles.add(person.role)
        if (!entry.tasks.some((item) => item.id === task.id)) {
          entry.tasks.push({ id: task.id, title: task.title })
        }
        byPerson.set(key, entry)
      }
    }

    return [...byPerson.values()].sort((a, b) => a.name.localeCompare(b.name))
  }, [job.tasks])

  // Files the crew put on tasks and materials from the mobile app.
  const fieldFiles = useMemo(
    () =>
      job.tasks
        .flatMap((task) => [
          ...task.attachments.map((file) => ({ file, source: task.title, kind: 'Task' })),
          ...task.materialLines.flatMap((line) =>
            line.attachments.map((file) => ({ file, source: `${line.description} · ${task.title}`, kind: 'Material' })),
          ),
        ])
        .sort((a, b) => b.file.createdAt.localeCompare(a.file.createdAt)),
    [job.tasks],
  )

  const tabs: readonly { key: JobTab; label: string; count?: number }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'tasks', label: 'Tasks', count: tasksTotal },
    { key: 'team', label: 'Crew', count: taskCrew.length },
    { key: 'estimates', label: 'Estimates', count: job.estimates.length },
    ...(changeOrders ? [{ key: 'changeOrders' as const, label: 'Change Orders', count: changeOrders.items.length }] : []),
    { key: 'notes', label: 'Notes', count: job.notes.length },
    { key: 'documents', label: 'Documents', count: job.attachments.length + fieldFiles.length },
  ]

  const hasFieldNotes = job.tasks.some(
    (task) =>
      task.comments.length > 0 ||
      task.attachments.length > 0 ||
      task.materialLines.some((line) => line.comments.length > 0 || line.attachments.length > 0),
  )

  return (
    <PageTransition>
      <Head title={job.name} />

      {/* ==================================================== Header ========= */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <Link
            href={back.url}
            className="inline-flex items-center gap-2 text-md font-medium text-white transition-colors hover:text-brand"
          >
            <ArrowLeft size={17} aria-hidden />
            {back.label}
          </Link>
          <h1 className="mt-3 text-3xl font-bold text-white sm:text-4xl">{job.name}</h1>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          {!isLocked && permitted('jobs.edit') && (
            <ButtonLink href={routeTo.jobEditFrom(job.id, from)} variant="white" leftIcon={PencilLine}>
              Edit Job
            </ButtonLink>
          )}
          {/*
            Only once completed — an in-progress job has nothing final to
            bill yet. Already invoiced opens that invoice instead of
            starting a second one; enforced again server-side, not only here.
          */}
          {isLocked && canCreateInvoice && (
            <ButtonLink
              href={
                job.hasInvoice && job.invoiceId
                  ? routeTo.invoice(job.invoiceId)
                  : routeTo.invoiceCreateForJob(job.id)
              }
              variant="dark"
              leftIcon={Receipt}
            >
              {job.hasInvoice ? 'View Invoice' : 'Create Invoice'}
            </ButtonLink>
          )}
          {!isLocked && (
            <MoreMenu
              ariaLabel="Add action"
              label={
                <span className="inline-flex items-center gap-2 font-medium text-white">
                  <Plus size={16} aria-hidden />
                  Add Action
                </span>
              }
              items={[
                ...(canPlanWork && permitted('tasks.create')
                  ? [
                      {
                        label: 'Add task',
                        icon: ListChecks,
                        onSelect: () => router.visit(routeTo.jobTaskSetupFromJob(job.id, from)),
                      },
                    ]
                  : []),
                ...(changeOrders?.canRaise
                  ? [
                      {
                        label: 'Create change order',
                        icon: FilePen,
                        onSelect: () => router.visit(routeTo.changeOrderForJob(job.id)),
                      },
                    ]
                  : []),
                { label: 'Add note', icon: NotebookPen, onSelect: () => setTab('notes') },
                { label: 'Add document', icon: FileUp, onSelect: () => setTab('documents') },
                ...(permitted('jobs.delete')
                  ? [{ label: 'Delete job', icon: Trash2, destructive: true, onSelect: deleteDialog.open }]
                  : []),
              ]}
            />
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

      {/* Client / project / location / dates, and the status. */}
      <div className="mt-5 flex flex-wrap items-center justify-between gap-x-8 gap-y-4">
        <dl className="flex flex-wrap gap-x-10 gap-y-4">
          <MetaItem icon={Building2} label="Client" value={job.client ?? '—'} />
          <MetaItem icon={FolderKanban} label="Project" value={job.takeoff?.projectName ?? '—'} />
          <MetaItem icon={MapPin} label="Location" value={job.location ?? '—'} />
          <MetaItem
            icon={CalendarDays}
            label="Start / End"
            value={
              job.startDate || job.endDate
                ? `${job.startDate ? formatCalendarDate(job.startDate) : '—'} – ${job.endDate ? formatCalendarDate(job.endDate) : '—'}`
                : '—'
            }
          />
        </dl>

        <div className="flex items-center gap-3">
          {job.isArchived && (
            <Badge tone="warning" icon={Archive}>
              Archived
            </Badge>
          )}
          <StatusSelect
            status={job.status}
            disabled={isLocked}
            onChange={changeStatus}
          />
        </div>
      </div>

      {/* ====================================================== Tabs ========= */}
      <nav
        aria-label="Job sections"
        className="mt-5 flex gap-1 overflow-x-auto rounded-card border border-hairline glass px-2"
      >
        {tabs.map((item) => (
          <button
            key={item.key}
            type="button"
            onClick={() => setTab(item.key)}
            aria-current={tab === item.key ? 'page' : undefined}
            className={cn(
              'relative shrink-0 px-5 py-4 text-md font-medium transition-colors',
              tab === item.key ? 'text-white' : 'text-white/75 hover:text-white',
            )}
          >
            {item.label}
            {item.count !== undefined && item.count > 0 && (
              <span className="ml-2 rounded-full bg-white/12 px-2 py-0.5 text-2xs text-white/85">
                {item.count}
              </span>
            )}
            {tab === item.key && (
              <span className="absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-brand" aria-hidden />
            )}
          </button>
        ))}
      </nav>

      {/* =================================================== Overview ======== */}
      {tab === 'overview' && (
        <div className="mt-5 space-y-5">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard icon={FileText} label="Job Value" value={money(jobValue)} />
            <StatCard
              icon={Database}
              label="Billed to Date"
              value={money(jobCosting.billed)}
              {...(billedPct ? { note: `${billedPct}%` } : {})}
            />
            <StatCard icon={Calculator} label="Estimated Cost" value={money(jobCosting.estimatedTotalCost)} />
            <StatCard
              icon={BarChart3}
              label="Projected Margin"
              value={jobCosting.marginPct === null ? '—' : `${jobCosting.marginPct.toFixed(1)}%`}
              {...(jobCosting.profit !== null ? { note: money(jobCosting.profit) } : {})}
            />
          </div>

          <div className="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <Card padding="md">
              <h2 className="text-lg font-semibold text-white">Progress</h2>
              <div className="mt-4 flex items-center gap-4">
                <div
                  className="h-3.5 flex-1 overflow-hidden rounded-full bg-black/35"
                  role="progressbar"
                  aria-valuenow={progress}
                  aria-valuemin={0}
                  aria-valuemax={100}
                >
                  <div
                    className="h-full rounded-full bg-linear-to-r from-status-success/40 to-status-success transition-[width] duration-700"
                    style={{ width: `${progress}%` }}
                  />
                </div>
                <span className="w-12 text-right text-md font-semibold text-white">{progress}%</span>
              </div>

              <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <ProgressFact icon={ListChecks} label="Tasks" value={`${tasksDone} / ${tasksTotal}`} />
                <ProgressFact
                  icon={Clock}
                  label="Time"
                  value={`${formatHours(actualHours)} / ${formatHours(estimatedHours)} hrs`}
                />
                <ProgressFact icon={FileText} label="Estimates" value={String(job.estimates.length)} />
                <ProgressFact icon={Users} label="Crew" value={String(job.team.length)} />
              </div>
            </Card>

            <Card padding="md">
              <h2 className="text-lg font-semibold text-white">Quick Actions</h2>
              <div className="mt-4 grid gap-3 sm:grid-cols-2">
                {canPlanWork && !isLocked && (
                  <QuickAction
                    icon={ClipboardList}
                    label="Create Task"
                    onClick={() => router.visit(routeTo.jobTaskSetupFromJob(job.id, from))}
                  />
                )}
                {changeOrders?.canRaise && (
                  <QuickAction
                    icon={FilePen}
                    label="Create Change Order"
                    onClick={() => router.visit(routeTo.changeOrderForJob(job.id))}
                  />
                )}
                <QuickAction icon={NotebookPen} label="Add Note" onClick={() => setTab('notes')} />
                <QuickAction icon={FileUp} label="Add Document" onClick={() => setTab('documents')} />
                <QuickAction icon={FileText} label="View Estimates" onClick={() => setTab('estimates')} />
              </div>
            </Card>
          </div>

          {job.takeoff && <JobTakeoffPanel takeoff={job.takeoff} />}

          <Card padding="md">
            <div className="flex items-center justify-between">
              <h2 className="text-lg font-semibold text-white">Recent Activity</h2>
            </div>
            {job.activities.length === 0 ? (
              <p className="mt-3 text-md text-white/75">Nothing has happened on this job yet.</p>
            ) : (
              <ul className="mt-3 divide-y divide-hairline">
                {job.activities.slice(0, 6).map((activity) => (
                  <li key={activity.id} className="flex items-center gap-4 py-3">
                    <span className="grid size-10 shrink-0 place-items-center rounded-full bg-white/8 text-brand ring-1 ring-hairline">
                      <Activity size={17} aria-hidden />
                    </span>
                    <p className="min-w-0 flex-1 truncate text-md text-white">{activity.description}</p>
                    <span className="shrink-0 text-right text-xs text-white/70">
                      {formatRelative(activity.createdAt)}
                      <br />
                      by {activity.actor}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      {/* ====================================================== Tasks ======== */}
      {tab === 'tasks' && (
        <Card padding="md" className="mt-5">
          <SectionHeading
            as="h3"
            title="Tasks"
            subtitle={`${tasksTotal} on this job`}
            actions={
              canPlanWork && !isLocked ? (
                <ButtonLink
                  href={routeTo.jobTaskSetupFromJob(job.id, from)}
                  variant="secondary"
                  size="sm"
                  leftIcon={Plus}
                >
                  Add task
                </ButtonLink>
              ) : undefined
            }
          />
          <JobTasksPanel tasks={job.tasks} canPlan={canPlanWork && !isLocked} jobOrigin={from} />

          {hasFieldNotes && (
            <div className="mt-8 border-t border-hairline pt-6">
              <SectionHeading
                as="h3"
                title="Field Notes & Photos"
                subtitle="Left by the crew, from the mobile app — by task, then by material"
              />
              <JobTaskFieldNotesPanel tasks={job.tasks} />
            </div>
          )}
        </Card>
      )}

      {/* ======================================================= Team ======== */}
      {tab === 'team' && (
        <div className="mt-5 grid gap-5 xl:grid-cols-2">
          <Card padding="md" className="xl:col-span-2">
            <SectionHeading
              as="h3"
              title="Crew on this job's tasks"
              subtitle={`${taskCrew.length} ${taskCrew.length === 1 ? 'person' : 'people'} across ${tasksTotal} ${tasksTotal === 1 ? 'task' : 'tasks'}`}
            />
            {taskCrew.length === 0 ? (
              <p className="text-md text-white/75">Nobody is assigned to a task on this job yet.</p>
            ) : (
              <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {taskCrew.map((person) => (
                  <li
                    key={person.name}
                    className="rounded-panel border border-hairline bg-white/4 p-4"
                  >
                    <div className="flex items-center gap-3">
                      <span className="grid size-10 shrink-0 place-items-center rounded-full bg-brand/15 text-sm font-semibold text-brand ring-1 ring-brand/30">
                        {person.initials ?? person.name.slice(0, 2).toUpperCase()}
                      </span>
                      <div className="min-w-0">
                        <p className="truncate font-semibold text-white">{person.name}</p>
                        <p className="truncate text-sm text-white/70">{[...person.roles].join(' · ')}</p>
                      </div>
                    </div>
                    <p className="mt-3 text-2xs tracking-wide text-white/60 uppercase">
                      {person.tasks.length} {person.tasks.length === 1 ? 'task' : 'tasks'}
                    </p>
                    <ul className="mt-1.5 space-y-1">
                      {person.tasks.map((task) => (
                        <li key={task.id} className="truncate text-sm text-white/90">
                          {task.title}
                        </li>
                      ))}
                    </ul>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          {/*
            Read/manage only: a foreman makes the actual assignment from the
            mobile app's Job Detail screen, not from here.
          */}
          <Card padding="md" className="xl:col-span-2">
            <SectionHeading
              as="h3"
              title="Apprentices"
              subtitle={`${apprenticeAssignments.length} assigned to this job`}
            />
            {apprenticeAssignments.length === 0 ? (
              <p className="text-md text-white/75">No apprentice assigned yet.</p>
            ) : (
              <ul className="space-y-2">
                {apprenticeAssignments.map((assignment) => (
                  <li
                    key={assignment.id}
                    className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 px-4 py-3"
                  >
                    <span className="min-w-0">
                      <span className="block truncate font-semibold text-white">
                        {assignment.apprenticeName}
                      </span>
                      <span className="block truncate text-sm text-white/65">
                        under {assignment.journeymanName}
                      </span>
                    </span>
                    {canManageApprentices && !isLocked && (
                      <IconButton
                        icon={Trash2}
                        label={`Remove ${assignment.apprenticeName}`}
                        onClick={() => removeApprentice(assignment.id)}
                      />
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      {/* =================================================== Estimates ======= */}
      {tab === 'estimates' && (
        <Card padding="md" className="mt-5">
          <SectionHeading
            as="h3"
            title="Estimates"
            subtitle={`${job.estimates.length} raised for this job`}
          />
          <JobEstimatesPanel jobId={job.id} estimates={job.estimates} />
        </Card>
      )}

      {/* ================================================ Change orders ====== */}
      {tab === 'changeOrders' && changeOrders && (
        <Card padding="md" className="mt-5">
          <SectionHeading
            as="h3"
            title="Change Orders"
            subtitle="Work added to this job after it began"
            actions={
              changeOrders.canRaise ? (
                <ButtonLink href={routeTo.changeOrderForJob(job.id)} variant="secondary" size="sm" leftIcon={Plus}>
                  Create change order
                </ButtonLink>
              ) : undefined
            }
          />
          {changeOrders.items.length === 0 ? (
            <p className="text-sm text-white/75">
              No change orders on this job yet.
              {changeOrders.canRaise ? ' Create one when work is added after it began.' : ''}
            </p>
          ) : (
            <ul className="divide-y divide-hairline">
              {changeOrders.items.map((order) => (
                <li key={order.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                  <Link href={routeTo.changeOrder(order.id)} className="w-20 font-medium text-white hover:text-brand">
                    {order.label}
                  </Link>
                  <span className="min-w-0 flex-1 text-sm text-white/90">{order.description}</span>
                  <ChangeOrderSourcePill source={order.source} />
                  <span className="w-24 text-right text-sm tabular-nums text-white">{formatCurrency(order.amount)}</span>
                  <ChangeOrderStatusPill status={order.status} />
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {tab === 'notes' && (
        <Card padding="md" className="mt-5">
          <SectionHeading as="h3" title="Notes" subtitle={`${job.notes.length} recorded`} />
          <JobNotesPanel jobId={job.id} notes={job.notes} readOnly={isLocked} />
        </Card>
      )}

      {tab === 'documents' && (
        <div className="mt-5 space-y-5">
          <Card padding="md">
            <SectionHeading
              as="h3"
              title="Uploaded from the field"
              subtitle={`${fieldFiles.length} ${fieldFiles.length === 1 ? 'file' : 'files'} from the foreman and crew, via the mobile app`}
            />
            {fieldFiles.length === 0 ? (
              <p className="text-md text-white/75">
                Nothing has been uploaded from the field yet. Photos and files the crew adds to a
                task or material in the app appear here.
              </p>
            ) : (
              <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {fieldFiles.map(({ file, source, kind }) => (
                  <li key={`${kind}-${file.id}`}>
                    <a
                      href={file.url}
                      target="_blank"
                      rel="noreferrer"
                      className="flex h-full gap-3 rounded-panel border border-hairline bg-white/4 p-3 transition-colors hover:border-brand/50 hover:bg-white/8"
                    >
                      {file.mime?.startsWith('image/') ? (
                        <img
                          src={file.url}
                          alt=""
                          loading="lazy"
                          className="size-16 shrink-0 rounded-sm bg-white/10 object-cover"
                        />
                      ) : (
                        <span className="grid size-16 shrink-0 place-items-center rounded-sm bg-ocean-600/60 text-brand">
                          <FileText size={26} aria-hidden />
                        </span>
                      )}
                      <span className="min-w-0 flex-1">
                        <span className="block truncate font-semibold text-white" title={file.name}>
                          {file.name}
                        </span>
                        <span className="block truncate text-sm text-white/75" title={source}>
                          {kind}: {source}
                        </span>
                        <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-white/65">
                          <span>{file.uploadedBy}</span>
                          {file.uploadedByRole && (
                            <Badge tone="info" size="sm">
                              {file.uploadedByRole}
                            </Badge>
                          )}
                          <span>· {formatRelative(file.createdAt)}</span>
                        </span>
                      </span>
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card padding="md">
            <SectionHeading
              as="h3"
              title="Job documents"
              subtitle={`${job.attachments.length} uploaded`}
            />
            <JobAttachmentsPanel jobId={job.id} attachments={job.attachments} readOnly={isLocked} />
          </Card>
        </div>
      )}

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Delete “${job.name}”?`}
        description="The job and its timeline are removed from the list. You can undo this from the jobs screen."
        confirmLabel="Delete job"
        confirmVariant="danger"
        onConfirm={() => {
          router.delete(routeTo.job(job.id))
          deleteDialog.close()
        }}
        onCancel={deleteDialog.close}
      />
    </PageTransition>
  )
}


type JobTab = 'overview' | 'tasks' | 'team' | 'estimates' | 'changeOrders' | 'notes' | 'documents'

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

const STATUS_PILL: Record<Tone, string> = {
  brand: 'border-brand/50 bg-brand/15 text-brand',
  success: 'border-status-success/50 bg-status-success/15 text-status-success',
  warning: 'border-status-warning/50 bg-status-warning/15 text-status-warning',
  danger: 'border-status-danger/60 bg-status-danger/20 text-red-300',
  info: 'border-status-info/50 bg-status-info/15 text-status-info',
  purple: 'border-status-purple/50 bg-status-purple/15 text-status-purple',
  blue: 'border-status-blue/50 bg-status-blue/15 text-status-blue',
  neutral: 'border-hairline-strong bg-white/10 text-white',
}

/** The job's status as a pill that is also the control for changing it. */
function StatusSelect({
  status,
  disabled,
  onChange,
}: {
  status: JobStatus
  disabled: boolean
  onChange: (status: JobStatus) => void
}) {
  const tone = JOB_STATUS_TONE[status]

  return (
    <label
      className={cn(
        'relative inline-flex min-w-52 items-center gap-2.5 rounded-full border py-2.5 pr-10 pl-4 text-md font-semibold',
        STATUS_PILL[tone],
        disabled && 'opacity-80',
      )}
    >
      <span className={cn('size-2.5 rounded-full', TONE_DOT_CLASS[tone])} aria-hidden />
      <span className="sr-only">Status</span>
      <select
        id="job-status-select"
        value={status}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value as JobStatus)}
        className="w-full cursor-pointer appearance-none bg-transparent focus:outline-none disabled:cursor-not-allowed"
      >
        {JOB_STATUS_OPTIONS.map((option) => (
          <option key={option.value} value={option.value} className="bg-navy-900 text-white">
            {option.label}
          </option>
        ))}
      </select>
      <ChevronDown size={16} aria-hidden className="pointer-events-none absolute right-4" />
    </label>
  )
}

function StatCard({
  icon: Icon,
  label,
  value,
  note,
}: {
  icon: LucideIcon
  label: string
  value: string
  note?: string
}) {
  return (
    <Card padding="md" className="flex items-center gap-4">
      <span className="grid size-14 shrink-0 place-items-center rounded-panel bg-ocean-600/60 text-brand ring-1 ring-brand/25">
        <Icon size={24} aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="text-sm text-white/75">{label}</p>
        <p className="truncate text-2xl font-bold text-white">{value}</p>
        {note && <p className="text-sm text-white/65">{note}</p>}
      </div>
    </Card>
  )
}

function ProgressFact({ icon: Icon, label, value }: { icon: LucideIcon; label: string; value: string }) {
  return (
    <div className="flex items-center gap-3">
      <span className="grid size-11 shrink-0 place-items-center rounded-full bg-ocean-600/60 text-brand ring-1 ring-brand/25">
        <Icon size={19} aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="text-sm text-white/75">{label}</p>
        <p className="truncate text-md font-semibold text-white">{value}</p>
      </div>
    </div>
  )
}

function QuickAction({ icon: Icon, label, onClick }: { icon: LucideIcon; label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex items-center gap-4 rounded-panel border border-hairline bg-white/6 px-5 py-4 text-left text-md font-medium text-white transition-colors hover:border-brand/50 hover:bg-white/12"
    >
      <Icon size={22} aria-hidden className="shrink-0 text-brand" />
      {label}
    </button>
  )
}

JobShow.layout = appLayout
