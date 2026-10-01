import { useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  ArrowLeft,
  BadgeCheck,
  Briefcase,
  CheckCheck,
  ClipboardList,
  Clock,
  Mail,
  Phone,
  PencilLine,
  Trash2,
  UsersRound,
  type LucideIcon,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  EmptyState,
  Pagination,
  StatusChip,
  Table,
} from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES, TASK_STATUS_LABEL, TASK_STATUS_TONE, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type { SharedPageProps, TableColumn, TaskStatus } from '@/types'
import { formatDate, formatHours } from '@/utils'

interface ForemanDetail {
  readonly id: number
  readonly name: string
  readonly initials: string
  /** What they do on the crew: `foreman`, `journeyman` or `apprentice`. */
  readonly role: string
  readonly roleLabel: string
  /** The crew they are on, or null for someone not on one yet. */
  readonly team: { readonly id: number; readonly name: string } | null
  readonly phone: string | null
  readonly email: string | null
  readonly licenceNumber: string | null
  readonly joinedOn: string | null
  readonly notes: string | null
  readonly openTasks: number
  readonly openJobs: number
  readonly openHours: number
  readonly completedTasks: number
  /** Checked in at a site right now. */
  readonly onSite: boolean
}

interface ForemanTask {
  readonly id: number
  readonly title: string
  readonly status: TaskStatus
  readonly estimatedHours: number | null
  readonly jobId: number
  readonly jobName: string | null
  readonly client: string | null
  /** Their crew-register role — a foreman oversees a task, a journeyman/apprentice runs it. Both are work they carry. */
  readonly heldAs: 'foreman' | 'journeyman' | 'apprentice'
}

export interface ForemanShowProps {
  foreman: ForemanDetail
  /** Open work only — what they are carrying now. */
  tasks: readonly ForemanTask[]
  /** False for anyone who cannot staff work — the record is still readable. */
  canManage: boolean
}

/** Tasks per page — enough to see what they are on without a long scroll. */
const PAGE_SIZE = 5

/**
 * One crew member, and everything on record about them.
 *
 * The register answers "who has room". This answers what you ask once you have
 * picked someone: how to reach them, where they are, and exactly which jobs their
 * open tasks are on.
 */
export default function ForemanShow({ foreman, tasks, canManage }: ForemanShowProps) {
  const { flash } = usePage<SharedPageProps>().props
  const deleteDialog = useDisclosure()
  const [page, setPage] = useState(1)
  const isBusy = foreman.openTasks > 0
  const pageCount = Math.max(1, Math.ceil(tasks.length / PAGE_SIZE))
  // Clamped, so a task finished elsewhere never leaves this on a page that is gone.
  const currentPage = Math.min(page, pageCount)
  const visibleTasks = tasks.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  /*
   * Removing a member rewrites who ran their work, so the server refuses it for
   * anyone who has ever been handed a task. Said here too, rather than only on the
   * way back from a refused request.
   */
  const hasHistory = foreman.openTasks > 0 || foreman.completedTasks > 0

  const columns: TableColumn<ForemanTask>[] = [
    {
      key: 'task',
      header: 'Task',
      render: (task) => (
        <span className="flex flex-wrap items-center gap-2">
          <span className="font-medium text-white">{task.title}</span>
          {/* The list mixes work they run with work they are over, so each row says which. */}
          {task.heldAs === 'foreman' && (
            <Badge tone="info" size="sm">
              Overseeing
            </Badge>
          )}
        </span>
      ),
    },
    {
      key: 'job',
      header: 'Job',
      render: (task) => (
        <Link href={routeTo.job(task.jobId)} className="text-white transition-colors hover:text-brand">
          {task.jobName ?? 'Untitled job'}
        </Link>
      ),
    },
    {
      key: 'client',
      header: 'Client',
      render: (task) => <span className="text-white/85">{task.client ?? '—'}</span>,
    },
    {
      key: 'hours',
      header: 'Planned Hours',
      render: (task) => (
        <span className="whitespace-nowrap tabular-nums text-white">
          {task.estimatedHours === null ? '—' : formatHours(task.estimatedHours)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (task) => (
        <StatusChip pill tone={TASK_STATUS_TONE[task.status]} label={TASK_STATUS_LABEL[task.status]} />
      ),
    },
    ...(canManage
      ? [
          {
            key: 'actions',
            header: 'Actions',
            render: (task: ForemanTask) => (
              <ButtonLink href={routeTo.taskEdit(task.id)} variant="secondary" size="sm" leftIcon={PencilLine}>
                Edit task
              </ButtonLink>
            ),
          },
        ]
      : []),
  ]

  return (
    <PageTransition>
      <Head title={foreman.name} />

      {/* ==================================================== Header ========= */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <Link
            href={ROUTES.teams}
            className="inline-flex items-center gap-2 text-md font-medium text-white transition-colors hover:text-brand"
          >
            <ArrowLeft size={17} aria-hidden />
            Teams
          </Link>

          <div className="mt-3 flex flex-wrap items-center gap-3">
            <span
              aria-hidden
              className={
                isBusy
                  ? 'grid size-14 shrink-0 place-items-center rounded-full bg-brand/15 text-lg font-semibold text-brand ring-1 ring-brand/30'
                  : 'grid size-14 shrink-0 place-items-center rounded-full bg-white/8 text-lg font-semibold text-white/75 ring-1 ring-hairline'
              }
            >
              {foreman.initials}
            </span>
            <h1 className="text-3xl font-bold text-white sm:text-4xl">{foreman.name}</h1>
            <span className="inline-flex rounded-full border border-brand/40 bg-brand/10 px-3 py-1 text-xs font-medium text-brand">
              {foreman.roleLabel}
            </span>
            <StatusChip pill tone={isBusy ? 'brand' : 'success'} label={isBusy ? 'On work' : 'Free'} />
            <StatusChip
              pill
              tone={foreman.onSite ? 'info' : 'neutral'}
              label={foreman.onSite ? 'On site' : 'Available'}
            />
          </div>

          <p className="mt-2 text-md text-white/85">
            {foreman.team?.name ?? 'Not on a team'} ·{' '}
            {foreman.joinedOn ? `Joined ${formatDate(foreman.joinedOn)}` : 'Joining date not recorded'}
          </p>
        </div>

        {canManage && (
          <div className="flex flex-wrap items-center gap-3">
            <ButtonLink href={routeTo.foremanEdit(foreman.id)} variant="white" leftIcon={PencilLine}>
              Edit
            </ButtonLink>
            <Button variant="secondary" leftIcon={Trash2} disabled={hasHistory} onClick={deleteDialog.open}>
              Remove
            </Button>
          </div>
        )}
      </div>

      {/* The refusal to delete lands here, and it explains itself. */}
      <AnimatePresence initial={false}>
        {flash.warning && (
          <Alert key={flash.warning} tone="warning" className="mt-4">
            {flash.warning}
          </Alert>
        )}
        {flash.success && (
          <Alert key={flash.success} tone="success" className="mt-4">
            {flash.success}
          </Alert>
        )}
      </AnimatePresence>
      {canManage && hasHistory && (
        <p className="mt-2 text-xs text-white/60">
          They have been handed work, so removing them would erase who did it.
        </p>
      )}

      {/* ================================================= Summary cards ===== */}
      <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard icon={ClipboardList} label="Open Tasks" value={String(foreman.openTasks)} />
        <StatCard icon={Briefcase} label="Jobs" value={String(foreman.openJobs)} />
        <StatCard
          icon={Clock}
          label="Planned Hours"
          value={foreman.openHours > 0 ? formatHours(foreman.openHours) : '—'}
        />
        {/* Finished work says nothing about being busy, so it is stated apart. */}
        <StatCard icon={CheckCheck} label="Completed" value={String(foreman.completedTasks)} />
      </div>

      <div className="mt-5 grid items-stretch gap-5 xl:grid-cols-[minmax(0,1fr)_19rem]">
        {/* ================================================ Open work ======== */}
        <Card padding="sm" className="flex min-w-0 flex-col">
          <div className="flex flex-wrap items-center justify-between gap-3 px-1">
            <div>
              <h2 className="text-lg font-semibold text-white">Open Work</h2>
              <p className="mt-0.5 text-xs text-white/70">
                {tasks.length === 0
                  ? 'Nothing outstanding'
                  : `${tasks.length} ${tasks.length === 1 ? 'task' : 'tasks'} across ${foreman.openJobs} ${foreman.openJobs === 1 ? 'job' : 'jobs'}`}
              </p>
            </div>
            {tasks.length > 0 && (
              <ButtonLink
                href={`${ROUTES.tasks}?foreman=${encodeURIComponent(foreman.name)}`}
                variant="secondary"
                size="sm"
              >
                In the task list
              </ButtonLink>
            )}
          </div>

          {tasks.length === 0 ? (
            <EmptyState
              size="sm"
              icon={ClipboardList}
              title="Nothing outstanding"
              description="They have no open tasks, so they are free to be handed work."
            />
          ) : (
            <div className="mt-3 overflow-x-auto">
              <Table
                dense
                variant="lined"
                headerVariant="plain"
                className="min-w-2xl text-sm [&_th]:px-3 [&_th]:text-sm [&_td]:px-3 [&_td]:text-sm"
                columns={columns}
                rows={visibleTasks}
                getRowId={(task) => task.id}
                caption={`Open work for ${foreman.name}`}
              />
            </div>
          )}

          {tasks.length > PAGE_SIZE && (
            <Pagination
              withLabels
              className="mt-auto pt-4"
              page={currentPage}
              pageCount={pageCount}
              onPageChange={setPage}
              summary={`Showing ${(currentPage - 1) * PAGE_SIZE + 1}–${(currentPage - 1) * PAGE_SIZE + visibleTasks.length} of ${tasks.length} tasks`}
            />
          )}
        </Card>

        {/* ================================================== Details ========= */}
        <Card padding="md" className="min-w-0">
          <h2 className="text-lg font-semibold text-white">Details</h2>
          <p className="mt-0.5 text-xs text-white/70">What is on record for this member</p>

          <dl className="mt-4 space-y-4">
            <Detail icon={BadgeCheck} label="Role" value={foreman.roleLabel} />
            {/* "Not on a team" is a real answer, not a blank. */}
            <Detail icon={UsersRound} label="Team" value={foreman.team?.name ?? 'Not on a team'} />
            {/* Shown formatted, dialled bare: a tel: URI has no room for spaces or brackets. */}
            <Detail
              icon={Phone}
              label="Phone"
              value={foreman.phone}
              href={`tel:${(foreman.phone ?? '').replace(/[^\d+]/g, '')}`}
            />
            <Detail icon={Mail} label="Email" value={foreman.email} href={`mailto:${foreman.email ?? ''}`} />
            <Detail icon={BadgeCheck} label="Licence number" value={foreman.licenceNumber} />
            <Detail
              icon={Clock}
              label="Date of joining"
              value={foreman.joinedOn === null ? null : formatDate(foreman.joinedOn)}
            />
          </dl>

          <div className="mt-5 border-t border-hairline pt-4">
            <p className="text-sm text-white/70">Notes</p>
            <p
              className={
                foreman.notes
                  ? 'mt-1 text-md whitespace-pre-line text-white/90'
                  : 'mt-1 text-md text-white/50'
              }
            >
              {foreman.notes ?? 'Nothing recorded.'}
            </p>
          </div>
        </Card>
      </div>

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Remove “${foreman.name}”?`}
        description="They come off the register and can no longer be handed work. This cannot be undone."
        confirmLabel="Remove member"
        confirmVariant="danger"
        onConfirm={() => {
          router.delete(routeTo.foreman(foreman.id))
          deleteDialog.close()
        }}
        onCancel={deleteDialog.close}
      />
    </PageTransition>
  )
}

interface DetailProps {
  icon: LucideIcon
  label: string
  value: string | null
  /** Makes the value actionable when there is one — a number to call, say. */
  href?: string
}

function Detail({ icon: Icon, label, value, href }: DetailProps) {
  return (
    <div className="flex items-start gap-3">
      <span className="grid size-9 shrink-0 place-items-center rounded-panel bg-ocean-600/60 text-brand ring-1 ring-brand/25">
        <Icon size={16} aria-hidden />
      </span>
      <div className="min-w-0">
        <dt className="text-xs text-white/70">{label}</dt>
        <dd className="text-md">
          {value === null ? (
            <span className="text-white/50">Not recorded</span>
          ) : href ? (
            <a href={href} className="break-words text-white transition-colors hover:text-brand">
              {value}
            </a>
          ) : (
            <span className="break-words text-white">{value}</span>
          )}
        </dd>
      </div>
    </div>
  )
}

function StatCard({ icon: Icon, label, value }: { icon: LucideIcon; label: string; value: string }) {
  return (
    <Card padding="md" className="flex items-center gap-4">
      <span className="grid size-12 shrink-0 place-items-center rounded-panel bg-ocean-600/60 text-brand ring-1 ring-brand/25">
        <Icon size={22} aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-sm text-white/75">{label}</p>
        <p className="text-xl leading-tight font-bold tabular-nums text-white">{value}</p>
      </div>
    </Card>
  )
}

ForemanShow.layout = appLayout
