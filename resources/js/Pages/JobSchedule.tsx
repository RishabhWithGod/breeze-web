import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  AlertTriangle,
  ArrowLeft,
  Ban,
  CalendarClock,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Clock,
  Flag,
  GitBranch,
  Plus,
  Settings,
  Trash2,
  UserPlus,
  UserX,
} from 'lucide-react'
import {
  Alert,
  Badge,
  ButtonLink,
  Button,
  Card,
  CardHeader,
  ConfirmDialog,
  EmptyState,
  FilterTabs,
  IconBubble,
  MoreMenu,
  ProgressBar,
  StatusChip,
} from '@/components/common'
import {
  AssignTaskModal,
  CompleteTaskModal,
  DelayTaskModal,
  DependencyModal,
  MoveTaskModal,
  ScheduleSettingsModal,
  TaskFormModal,
} from '@/components/jobSchedule'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  ROUTES,
  routeTo,
  SCHEDULE_ACTIVITY_LABEL,
  SCHEDULE_STATE_LABEL,
  SCHEDULE_STATE_TONE,
  SCHEDULE_TABS,
  TASK_PRIORITY_LABEL,
  TASK_PRIORITY_TONE,
  TASK_STATUS_FILL,
  TASK_STATUS_LABEL,
  TASK_STATUS_TONE,
} from '@/constants'
import type { ScheduleTab } from '@/constants'
import { useDisclosure, useEchoConnectionState, usePrivateChannel } from '@/hooks'
import type { JobScheduleProps, ScheduleTask, SharedPageProps } from '@/types'
import { formatDate, formatHours } from '@/utils'

/**
 * A job's real schedule: one plan, real tasks, a real dependency graph and
 * real crew workload — every panel here is a different reading of the same
 * task set the backend already computed. Nothing on this page invents a
 * row: an empty panel means the backend genuinely returned nothing for it.
 */
export default function JobSchedule({
  job,
  schedule,
  tasks,
  progress,
  timeline,
  calendar,
  crewShifts,
  upcoming,
  delays,
  dependencies,
  resources,
  milestones,
  criticalPath,
  activity,
  members,
  options,
  can,
}: JobScheduleProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [tab, setTab] = useState<ScheduleTab>('overview')

  // Every task/dependency/assignment/settings mutation ends at the same
  // `ScheduleChanged` broadcast — one partial reload of the schedule's own
  // props covers all of them, whichever tab is open, without disturbing the
  // active tab, open modals, or the pending-delete confirmation.
  const resync = useCallback(() => {
    router.reload({
      only: [
        'schedule', 'tasks', 'progress', 'timeline', 'calendar', 'crewShifts',
        'upcoming', 'delays', 'dependencies', 'resources', 'milestones',
        'criticalPath', 'activity',
      ],
    })
  }, [])

  usePrivateChannel(`job.${job.id}`, { 'schedule.changed': resync })
  useEchoConnectionState(resync)
  const [selectedTask, setSelectedTask] = useState<ScheduleTask | null>(null)
  const [pendingDelete, setPendingDelete] = useState<ScheduleTask | null>(null)

  const settingsModal = useDisclosure()
  const taskForm = useDisclosure()
  const completeModal = useDisclosure()
  const delayModal = useDisclosure()
  const moveModal = useDisclosure()
  const assignModal = useDisclosure()
  const dependencyModal = useDisclosure()

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  const criticalSet = new Set(criticalPath)

  const openEdit = (task: ScheduleTask) => {
    setSelectedTask(task)
    taskForm.open()
  }

  const move = (task: ScheduleTask, direction: -1 | 1) => {
    const sorted = [...tasks].sort((a, b) => a.position - b.position)
    const index = sorted.findIndex((t) => t.id === task.id)
    const swapWith = sorted[index + direction]
    if (!swapWith) return

    const order = sorted.map((t) => t.id)
    const temp = order[index]
    order[index] = swapWith.id
    order[index + direction] = temp as number
    router.post(routeTo.jobTasksReorder(job.id), { order }, { preserveScroll: true })
  }

  const unassign = (task: ScheduleTask, assignmentId: number) => {
    router.delete(routeTo.scheduleTaskUnassign(task.id, assignmentId), { preserveScroll: true })
  }

  const removeDependency = (task: ScheduleTask, dependencyId: number) => {
    router.delete(routeTo.scheduleTaskDependencyDestroy(task.id, dependencyId), { preserveScroll: true })
  }

  const confirmDelete = () => {
    if (!pendingDelete) return
    router.delete(routeTo.scheduleTask(pendingDelete.id), { preserveScroll: true })
    setPendingDelete(null)
  }

  return (
    <PageTransition>
      <Head title={`${job.name} — Schedule`} />

      <PageHeader
        title={job.name}
        subtitle={job.client ?? undefined}
        breadcrumbs={[{ label: 'Jobs', href: ROUTES.jobs }, { label: job.name, href: routeTo.job(job.id) }, { label: 'Schedule' }]}
        actions={
          <div className="flex flex-wrap gap-3">
            {can.updateSchedule && (
              <Button variant="secondary" leftIcon={Settings} onClick={settingsModal.open}>
                Schedule Settings
              </Button>
            )}
            <ButtonLink href={routeTo.job(job.id)} variant="secondary" leftIcon={ArrowLeft}>
              Back to Job
            </ButtonLink>
          </div>
        }
      />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert key={displayNotice} tone={flash.warning ? 'warning' : 'success'} className="mb-6" onDismiss={() => setDismissed(displayNotice)}>
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      {/* ==================================================== Job + Schedule info */}
      <Card className="mb-6">
        <div className="flex flex-wrap items-center gap-3">
          <StatusChip hideDot tone={TASK_PRIORITY_TONE[job.priority]} label={`${TASK_PRIORITY_LABEL[job.priority]} Priority`} />
          <StatusChip hideDot tone={SCHEDULE_STATE_TONE[schedule.status]} label={`Schedule: ${SCHEDULE_STATE_LABEL[schedule.status]}`} />
          {job.foreman && <span className="text-sm text-white/70">Foreman: {job.foreman.name}</span>}
          {job.location && <span className="text-sm text-white/70">{job.location}</span>}
        </div>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <InfoStat label="Window" value={schedule.startsOn && schedule.endsOn ? `${formatDate(schedule.startsOn)} – ${formatDate(schedule.endsOn)}` : 'Not set'} />
          <InfoStat label="Working Hours" value={`${schedule.workStartTime}–${schedule.workEndTime} (${schedule.hoursPerDay}h/day)`} />
          <InfoStat label="Duration" value={`${schedule.durationWorkingDays} working days`} />
          <InfoStat label="Timezone" value={schedule.timezone} />
        </div>
      </Card>

      {/* ============================================================ Progress */}
      <Card className="mb-6">
        <CardHeader title="Progress" subtitle={progress.isBehind ? 'Behind the expected pace for today' : 'On pace'} />
        <div className="grid gap-6 sm:grid-cols-2">
          <div>
            <p className="mb-1 text-sm text-white/70">Tasks Complete — {progress.completed} of {progress.counted}</p>
            <ProgressBar value={progress.taskPct} tone="info" size="md" showValue />
          </div>
          <div>
            <p className="mb-1 text-sm text-white/70">Work Complete (by hours)</p>
            <ProgressBar value={progress.workPct} tone={progress.isBehind ? 'warning' : 'brand'} size="md" showValue />
          </div>
        </div>
        <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-6">
          <InfoStat label="Remaining" value={String(progress.remaining)} />
          <InfoStat label="In Progress" value={String(progress.inProgress)} />
          <InfoStat label="Blocked" value={String(progress.blocked)} />
          <InfoStat label="Delayed" value={String(progress.delayed)} />
          <InfoStat label="Cancelled" value={String(progress.cancelled)} />
          <InfoStat label="Milestones Met" value={`${progress.milestonesMet}/${progress.milestones}`} />
        </div>
      </Card>

      <FilterTabs options={SCHEDULE_TABS} value={tab} onChange={setTab} solid className="mb-6" />

      {tab === 'overview' && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader title="Upcoming" subtitle="Due in the next 14 days" />
            {upcoming.length === 0 ? (
              <EmptyState icon={Clock} title="Nothing due soon" />
            ) : (
              <ul className="space-y-3">
                {upcoming.map((task) => (
                  <li key={task.id} className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 p-3">
                    <div className="min-w-0">
                      <p className="truncate font-medium text-white">{task.title}</p>
                      <p className="text-sm text-white/70">{task.dueLabel} · {task.daysAway === 0 ? 'today' : `${task.daysAway}d away`}</p>
                    </div>
                    <StatusChip hideDot tone={TASK_STATUS_TONE[task.status]} label={TASK_STATUS_LABEL[task.status]} />
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <CardHeader title="Delays" subtitle="Late or explicitly delayed" />
            {delays.length === 0 ? (
              <EmptyState icon={CheckCircle2} title="Nothing is running late" />
            ) : (
              <ul className="space-y-3">
                {delays.map((task) => (
                  <li key={task.id} className="rounded-panel border border-status-danger/30 bg-status-danger/10 p-3">
                    <div className="flex items-start gap-2">
                      <AlertTriangle size={16} className="mt-0.5 shrink-0 text-status-warning" aria-hidden />
                      <div className="min-w-0 flex-1">
                        <p className="font-medium text-white">{task.title}</p>
                        <p className="text-sm text-white/85">{task.daysLate} days late{task.notes ? ` — ${task.notes}` : ''}</p>
                        {task.blockedBy.length > 0 && <p className="mt-1 text-xs text-white/60">Blocked by: {task.blockedBy.join(', ')}</p>}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <CardHeader title="Milestones" />
            {milestones.length === 0 ? (
              <EmptyState icon={Flag} title="No milestones on this schedule" />
            ) : (
              <ul className="space-y-2">
                {milestones.map((m) => (
                  <li key={m.id} className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 p-3">
                    <div className="flex items-center gap-2">
                      <Flag size={15} className={m.isMet ? 'text-status-success' : 'text-white/60'} aria-hidden />
                      <span className="text-white">{m.title}</span>
                    </div>
                    <span className="text-sm text-white/70">{m.dueLabel ?? '—'}</span>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <CardHeader title="Activity" />
            {activity.length === 0 ? (
              <EmptyState title="No activity recorded yet" />
            ) : (
              <ul className="space-y-2">
                {activity.map((row) => (
                  <li key={row.id} className="text-sm">
                    <span className="text-white">{SCHEDULE_ACTIVITY_LABEL[row.type] ?? row.type}</span>
                    <span className="text-white/60"> — {row.description}</span>
                    {row.author && <span className="text-white/50"> · {row.author}</span>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      {tab === 'tasks' && (
        <Card>
          <CardHeader
            title="Tasks"
            subtitle={`${tasks.length} task${tasks.length === 1 ? '' : 's'}`}
            actions={can.createTask ? <Button leftIcon={Plus} onClick={() => { setSelectedTask(null); taskForm.open() }}>Add Task</Button> : undefined}
          />
          {tasks.length === 0 ? (
            <EmptyState icon={Clock} title="No tasks on this schedule yet" />
          ) : (
            <ul className="space-y-3">
              {[...tasks].sort((a, b) => a.position - b.position).map((task, index, sorted) => (
                <li key={task.id} className={`rounded-panel border p-4 ${criticalSet.has(task.id) ? 'border-status-warning/50 bg-status-warning/5' : 'border-hairline bg-white/4'}`}>
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        {task.isMilestone && <Flag size={14} className="text-brand" aria-hidden />}
                        <p className="font-semibold text-white">{task.title}</p>
                        {criticalSet.has(task.id) && <Badge tone="warning">Critical Path</Badge>}
                      </div>
                      {task.description && <p className="mt-1 text-sm text-white/70">{task.description}</p>}
                      <div className="mt-2 flex flex-wrap items-center gap-2">
                        <StatusChip hideDot tone={TASK_STATUS_TONE[task.status]} label={TASK_STATUS_LABEL[task.status]} />
                        <StatusChip hideDot tone={TASK_PRIORITY_TONE[task.priority]} label={TASK_PRIORITY_LABEL[task.priority]} />
                        {task.category && <Badge>{task.category}</Badge>}
                        <span className="text-sm text-white/70">
                          {task.startsOn ? formatDate(task.startsOn) : '—'} → {task.endsOn ? formatDate(task.endsOn) : '—'}
                        </span>
                        <span className="text-sm text-white/70">{formatHours(task.actualHours)} / {task.estimatedHours !== null ? formatHours(task.estimatedHours) : '—'}</span>
                      </div>
                      {task.assignments.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-2">
                          {task.assignments.map((a) => (
                            <span key={a.id} className="inline-flex items-center gap-1 rounded-pill bg-white/10 px-2.5 py-1 text-xs text-white/85">
                              {a.member?.name ?? 'Unknown'} · {a.role}
                              {can.assign && (
                                <button type="button" aria-label={`Remove ${a.member?.name}`} onClick={() => unassign(task, a.id)} className="ml-1 text-white/50 hover:text-status-danger">
                                  <UserX size={12} aria-hidden />
                                </button>
                              )}
                            </span>
                          ))}
                        </div>
                      )}
                      {task.dependencies.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-2">
                          {task.dependencies.map((d) => (
                            <span key={d.id} className="inline-flex items-center gap-1 rounded-pill bg-white/10 px-2.5 py-1 text-xs text-white/70">
                              <GitBranch size={11} aria-hidden /> waits on {d.dependsOnTitle ?? 'Unknown'} ({d.typeLabel})
                              <button type="button" aria-label="Remove dependency" onClick={() => removeDependency(task, d.id)} className="ml-1 text-white/50 hover:text-status-danger">
                                <UserX size={12} aria-hidden />
                              </button>
                            </span>
                          ))}
                        </div>
                      )}
                    </div>

                    <div className="flex items-center gap-1">
                      {can.reorder && (
                        <>
                          <button type="button" aria-label="Move up" disabled={index === 0} onClick={() => move(task, -1)} className="rounded-full p-1.5 text-white/60 hover:bg-white/10 hover:text-white disabled:opacity-30">
                            <ChevronUp size={16} aria-hidden />
                          </button>
                          <button type="button" aria-label="Move down" disabled={index === sorted.length - 1} onClick={() => move(task, 1)} className="rounded-full p-1.5 text-white/60 hover:bg-white/10 hover:text-white disabled:opacity-30">
                            <ChevronDown size={16} aria-hidden />
                          </button>
                        </>
                      )}
                      <MoreMenu
                        ariaLabel={`Actions for ${task.title}`}
                        items={[
                          { label: 'Edit', onSelect: () => openEdit(task) },
                          ...(task.status !== 'completed' && task.status !== 'cancelled' ? [{ label: 'Complete', icon: CheckCircle2, onSelect: () => { setSelectedTask(task); completeModal.open() } }] : []),
                          { label: 'Mark Delayed', icon: AlertTriangle, onSelect: () => { setSelectedTask(task); delayModal.open() } },
                          { label: 'Reschedule', icon: CalendarClock, onSelect: () => { setSelectedTask(task); moveModal.open() } },
                          ...(can.assign ? [{ label: 'Assign', icon: UserPlus, onSelect: () => { setSelectedTask(task); assignModal.open() } }] : []),
                          { label: 'Add Dependency', icon: GitBranch, onSelect: () => { setSelectedTask(task); dependencyModal.open() } },
                          ...(can.deleteTask ? [{ label: 'Delete', icon: Trash2, destructive: true, onSelect: () => setPendingDelete(task) }] : []),
                        ]}
                      />
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {tab === 'timeline' && (
        <Card>
          <CardHeader title="Timeline" subtitle={timeline.from && timeline.to ? `${formatDate(timeline.from)} – ${formatDate(timeline.to)}` : undefined} />
          {timeline.bars.length === 0 ? (
            <EmptyState icon={Clock} title="No dated tasks to plot yet" />
          ) : (
            <div className="space-y-2">
              {timeline.months.length > 0 && (
                <div className="relative mb-1 h-5 border-b border-hairline text-xs text-white/60">
                  {timeline.months.map((m) => (
                    <span key={m.label} className="absolute" style={{ left: `${m.offsetPct}%` }}>
                      {m.label}
                    </span>
                  ))}
                </div>
              )}
              {timeline.bars.map((bar) => (
                <div key={bar.taskId} className="flex items-center gap-3">
                  <span className="w-48 shrink-0 truncate text-sm text-white/85">{bar.title}</span>
                  <div className="relative h-6 flex-1 rounded-full bg-white/5">
                    <div
                      className={`absolute top-0 h-6 rounded-full ${TASK_STATUS_FILL[bar.status]} ${criticalSet.has(bar.taskId) ? 'ring-2 ring-status-warning' : ''}`}
                      style={{ left: `${bar.offsetPct}%`, width: `${Math.max(1, bar.widthPct)}%` }}
                      title={`${bar.title}: ${bar.startsOn} → ${bar.endsOn}`}
                    />
                    {timeline.todayOffsetPct !== null && timeline.todayOffsetPct !== undefined && (
                      <div className="absolute top-0 h-6 w-px bg-white/60" style={{ left: `${timeline.todayOffsetPct}%` }} />
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {tab === 'calendar' && (
        <Card>
          <CardHeader title={calendar.monthLabel} />
          <div className="grid grid-cols-7 gap-1 text-center text-xs text-white/60">
            {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((d) => (
              <div key={d} className="py-1">{d}</div>
            ))}
          </div>
          <div className="grid grid-cols-7 gap-1">
            {calendar.days.map((day) => (
              <div
                key={day.date}
                className={`min-h-20 rounded-panel border p-1.5 text-xs ${
                  day.isCurrentPeriod ? 'border-hairline bg-white/4' : 'border-transparent bg-transparent opacity-40'
                } ${day.isToday ? 'ring-1 ring-brand' : ''}`}
              >
                <div className="flex items-center justify-between">
                  <span className={day.isHoliday ? 'text-status-warning' : 'text-white/70'}>{day.dayOfMonth}</span>
                  {!day.isWorkingDay && <Ban size={10} className="text-white/40" aria-hidden />}
                </div>
                <div className="mt-1 space-y-0.5">
                  {day.items.slice(0, 3).map((item) => (
                    <div key={item.taskId} className={`truncate rounded px-1 py-0.5 text-2xs text-white ${TASK_STATUS_FILL[item.status]}`}>
                      {item.isMilestone && '★ '}{item.title}
                    </div>
                  ))}
                  {day.items.length > 3 && <div className="text-2xs text-white/50">+{day.items.length - 3} more</div>}
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}

      {tab === 'crew' && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader title="Workload" subtitle="Hours assigned on this job" />
            {resources.length === 0 ? (
              <EmptyState title="Nobody assigned yet" />
            ) : (
              <ul className="space-y-3">
                {resources.map((r) => (
                  <li key={r.id} className="rounded-panel border border-hairline bg-white/4 p-3">
                    <div className="flex items-center justify-between gap-3">
                      <div className="flex items-center gap-2">
                        <IconBubble icon={UserPlus} tone="brand" size="sm" />
                        <div>
                          <p className="font-medium text-white">{r.name}</p>
                          <p className="text-xs text-white/60">{r.roles.join(', ')}</p>
                        </div>
                      </div>
                      <div className="text-right text-sm text-white/85">
                        <p>{formatHours(r.hours)} · {r.taskCount} task{r.taskCount === 1 ? '' : 's'}</p>
                        {r.lateCount > 0 && <p className="text-status-danger">{r.lateCount} late</p>}
                        {r.shiftsElsewhere > 0 && <p className="text-white/50">{r.shiftsElsewhere} shift{r.shiftsElsewhere === 1 ? '' : 's'} elsewhere</p>}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <CardHeader title="Crew Shifts" subtitle="Booked on this job" />
            {crewShifts.length === 0 ? (
              <EmptyState title="No shifts booked on this job yet" />
            ) : (
              <ul className="space-y-2">
                {crewShifts.map((shift) => (
                  <li key={shift.id} className="flex items-center justify-between rounded-panel border border-hairline bg-white/4 p-3">
                    <div>
                      <p className="text-white">{shift.member?.name ?? 'Unassigned'}</p>
                      <p className="text-sm text-white/70">{formatDate(shift.date)} · {shift.startLabel}–{shift.endLabel}</p>
                    </div>
                    <span className="text-sm text-white/70">{formatHours(shift.durationHours)}</span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}

      {tab === 'dependencies' && (
        <div className="space-y-6">
          {dependencies.breaches.length > 0 && (
            <Card>
              <CardHeader title="Broken Constraints" />
              <ul className="space-y-2">
                {dependencies.breaches.map((b, i) => (
                  <li key={i} className="flex items-start gap-2 rounded-panel border border-status-danger/30 bg-status-danger/10 p-3">
                    <AlertTriangle size={16} className="mt-0.5 shrink-0 text-status-warning" aria-hidden />
                    <p className="text-sm text-white/90">{b.problem}</p>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          <Card>
            <CardHeader title="Dependency Graph" subtitle={`${dependencies.edges.length} dependencies`} />
            {dependencies.edges.length === 0 ? (
              <EmptyState icon={GitBranch} title="No dependencies wired up yet" />
            ) : (
              <ul className="space-y-2">
                {dependencies.edges.map((edge) => (
                  <li key={edge.id} className="flex items-center justify-between rounded-panel border border-hairline bg-white/4 p-3 text-sm">
                    <span className="text-white">{edge.taskTitle}</span>
                    <span className="text-white/60">waits on</span>
                    <span className="text-white">{edge.dependsOnTitle}</span>
                    <Badge>{edge.typeLabel}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          {dependencies.readyToStart.length > 0 && (
            <Card>
              <CardHeader title="Ready to Start" subtitle="Every dependency is satisfied" />
              <ul className="flex flex-wrap gap-2">
                {dependencies.readyToStart.map((id) => {
                  const t = tasks.find((task) => task.id === id)
                  return t ? <Badge key={id} tone="success">{t.title}</Badge> : null
                })}
              </ul>
            </Card>
          )}
        </div>
      )}

      <TaskFormModal isOpen={taskForm.isOpen} onClose={taskForm.close} jobId={job.id} task={selectedTask} categories={options.categories} statuses={options.statuses} />
      <CompleteTaskModal isOpen={completeModal.isOpen} onClose={completeModal.close} task={selectedTask} />
      <DelayTaskModal isOpen={delayModal.isOpen} onClose={delayModal.close} task={selectedTask} />
      <MoveTaskModal isOpen={moveModal.isOpen} onClose={moveModal.close} task={selectedTask} />
      <AssignTaskModal isOpen={assignModal.isOpen} onClose={assignModal.close} task={selectedTask} members={members} roles={options.roles} />
      <DependencyModal isOpen={dependencyModal.isOpen} onClose={dependencyModal.close} task={selectedTask} otherTasks={tasks} />
      <ScheduleSettingsModal isOpen={settingsModal.isOpen} onClose={settingsModal.close} jobId={job.id} schedule={schedule} scheduleStatuses={options.scheduleStatuses} />

      <ConfirmDialog
        isOpen={pendingDelete !== null}
        tone="danger"
        title={`Remove "${pendingDelete?.title ?? ''}"?`}
        description="This removes the task and any dependencies pointing at it."
        confirmLabel="Remove"
        confirmVariant="danger"
        onConfirm={confirmDelete}
        onCancel={() => setPendingDelete(null)}
      />
    </PageTransition>
  )
}

function InfoStat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs tracking-wide text-white/60 uppercase">{label}</p>
      <p className="mt-1 text-md font-medium text-white">{value}</p>
    </div>
  )
}

JobSchedule.layout = appLayout
