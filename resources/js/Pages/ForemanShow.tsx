import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  ArrowLeft,
  Briefcase,
  CheckCheck,
  ClipboardList,
  Clock,
  PencilLine,
  Trash2,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  ConfirmDialog,
  EmptyState,
  StatusChip,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, TASK_STATUS_LABEL, TASK_STATUS_TONE, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type { SharedPageProps, TaskStatus } from '@/types'
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

/**
 * One foreman, and everything on record about them.
 *
 * The register answers "who has room". This answers what you ask once you have
 * picked someone: how to reach them, what lets them sign off work, and exactly
 * which jobs their open tasks are on.
 */
export default function ForemanShow({ foreman, tasks, canManage }: ForemanShowProps) {
  const { flash } = usePage<SharedPageProps>().props
  const deleteDialog = useDisclosure()
  const isBusy = foreman.openTasks > 0

  /*
   * Removing a foreman rewrites who ran their work, so the server refuses it
   * for anyone who has ever been handed a task. Said here too, rather than only
   * on the way back from a refused request.
   */
  const hasHistory = foreman.openTasks > 0 || foreman.completedTasks > 0

  return (
    <PageTransition>
      <Head title={foreman.name} />

      <PageHeader
        title={foreman.name}
        subtitle={`${foreman.roleLabel} · ${foreman.team?.name ?? 'Not on a team'}`}
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Teams', href: ROUTES.teams },
          { label: foreman.name },
        ]}
        actions={
          <>
            {canManage && (
              <ButtonLink
                href={routeTo.foremanEdit(foreman.id)}
                variant="secondary"
                leftIcon={PencilLine}
              >
                Edit
              </ButtonLink>
            )}
            <ButtonLink href={ROUTES.teams} variant="secondary" leftIcon={ArrowLeft}>
              Back
            </ButtonLink>
          </>
        }
      />

      {/* The refusal to delete lands here, and it explains itself. */}
      <AnimatePresence initial={false}>
        {flash.warning && (
          <Alert key={flash.warning} tone="warning" className="mb-6">
            {flash.warning}
          </Alert>
        )}
      </AnimatePresence>

      {/* ==================================================== Identity ======== */}
      <Card accent="brand" padding="lg">
        <div className="flex flex-wrap items-center gap-4">
          <span
            aria-hidden
            className={
              isBusy
                ? 'grid size-14 shrink-0 place-items-center rounded-full bg-brand/15 text-lg font-semibold text-brand ring-1 ring-brand/30'
                : 'grid size-14 shrink-0 place-items-center rounded-full bg-white/8 text-lg font-semibold text-white/70 ring-1 ring-hairline'
            }
          >
            {foreman.initials}
          </span>

          <div className="min-w-0 flex-1">
            <h2 className="truncate text-xl font-semibold text-white">{foreman.name}</h2>
            <p className="mt-0.5 text-sm text-white/70">
              {foreman.joinedOn
                ? `Joined ${formatDate(foreman.joinedOn)}`
                : 'Joining date not recorded'}
            </p>
          </div>

          <StatusChip
            hideDot
            tone={isBusy ? 'brand' : 'neutral'}
            label={isBusy ? 'On work' : 'Free'}
          />
        </div>

        <dl className="mt-6 grid gap-4 border-t border-hairline pt-6 sm:grid-cols-2 lg:grid-cols-4">
          <Stat icon={ClipboardList} label="Open tasks" value={String(foreman.openTasks)} />
          <Stat icon={Briefcase} label="Jobs" value={String(foreman.openJobs)} />
          <Stat
            icon={Clock}
            label="Hours"
            value={foreman.openHours > 0 ? formatHours(foreman.openHours) : '—'}
          />
          {/* Finished work says nothing about being busy, so it is stated apart. */}
          <Stat icon={CheckCheck} label="Completed" value={String(foreman.completedTasks)} />
        </dl>
      </Card>

      <div className="mt-6 grid gap-6 xl:grid-cols-[1fr_1.4fr]">
        {/* ================================================== Details ========= */}
        <Card accent="success" padding="lg" className="min-w-0 self-start">
          <CardHeader title="Details" subtitle="What is on record for this member" />

          <dl className="flex flex-col gap-4">
            {/*
              Role and crew first: they are what the rest of the record is about.
              "Not on a team" is a real answer, not a blank.
            */}
            <Detail label="Role" value={foreman.roleLabel} />
            <Detail label="Team" value={foreman.team?.name ?? 'Not on a team'} />
            {/*
              Shown formatted, dialled bare: a tel: URI has no room for spaces
              or brackets, however good they look on the page.
            */}
            <Detail
              label="Phone"
              value={foreman.phone}
              href={`tel:${(foreman.phone ?? '').replace(/[^\d+]/g, '')}`}
            />
            <Detail
              label="Email"
              value={foreman.email}
              href={`mailto:${foreman.email ?? ''}`}
            />
            <Detail label="Licence number" value={foreman.licenceNumber} />
            <Detail
              label="Date of joining"
              value={foreman.joinedOn === null ? null : formatDate(foreman.joinedOn)}
            />
          </dl>

          <div className="mt-6 border-t border-hairline pt-6">
            <p className="text-sm text-white/60">Notes</p>
            <p
              className={
                foreman.notes
                  ? 'mt-1 text-md whitespace-pre-line text-white/90'
                  : 'mt-1 text-md text-white/45'
              }
            >
              {foreman.notes ?? 'Nothing recorded.'}
            </p>
          </div>

          {canManage && (
            <div className="mt-6 border-t border-hairline pt-6">
              <Button
                variant="danger"
                leftIcon={Trash2}
                disabled={hasHistory}
                onClick={deleteDialog.open}
              >
                Remove from register
              </Button>
              {hasHistory && (
                <p className="mt-2 text-sm text-white/60">
                  They have run work, so removing them would erase who did it.
                </p>
              )}
            </div>
          )}
        </Card>

        {/* ============================================== Open work =========== */}
        <Card accent="warning" padding="lg" className="min-w-0">
          <CardHeader
            title="Open work"
            subtitle={
              tasks.length === 0
                ? 'Nothing outstanding'
                : `${tasks.length} ${tasks.length === 1 ? 'task' : 'tasks'} across ${foreman.openJobs} ${foreman.openJobs === 1 ? 'job' : 'jobs'}`
            }
            {...(tasks.length > 0
              ? {
                  actions: (
                    <ButtonLink
                      href={`${ROUTES.tasks}?foreman=${encodeURIComponent(foreman.name)}`}
                      variant="secondary"
                      size="sm"
                    >
                      In the task list
                    </ButtonLink>
                  ),
                }
              : {})}
          />

          {tasks.length === 0 ? (
            <EmptyState
              size="sm"
              icon={ClipboardList}
              title="Nothing outstanding"
              description="They have no open tasks, so they are free to be handed work."
            />
          ) : (
            <ul className="space-y-3">
              {tasks.map((task, index) => (
                <motion.li
                  key={task.id}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.25, delay: Math.min(index, 6) * 0.04 }}
                  className="rounded-panel border border-hairline bg-white/4 p-4"
                >
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate font-semibold text-white">
                        {task.title}
                        {/* The list mixes work they run with work they are
                            over, so each row says which. */}
                        {task.heldAs === 'foreman' && (
                          <Badge tone="info" size="sm" className="ml-2">
                            Overseeing
                          </Badge>
                        )}
                      </p>
                      <p className="mt-0.5 truncate text-sm text-white/70">
                        <Link
                          href={routeTo.job(task.jobId)}
                          className="transition-colors hover:text-brand"
                        >
                          {task.jobName ?? 'Untitled job'}
                        </Link>
                        {task.client && ` · ${task.client}`}
                      </p>
                    </div>

                    <div className="flex items-center gap-3">
                      <span className="whitespace-nowrap text-md font-semibold tabular-nums text-white">
                        {task.estimatedHours === null ? '—' : formatHours(task.estimatedHours)}
                      </span>
                      <StatusChip
                        hideDot
                        tone={TASK_STATUS_TONE[task.status]}
                        label={TASK_STATUS_LABEL[task.status]}
                      />
                    </div>
                  </div>

                  {canManage && (
                    <div className="mt-3 border-t border-hairline pt-3">
                      <ButtonLink
                        href={routeTo.taskEdit(task.id)}
                        variant="ghost"
                        size="sm"
                      >
                        Edit task
                      </ButtonLink>
                    </div>
                  )}
                </motion.li>
              ))}
            </ul>
          )}
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
  label: string
  value: string | null
  /** Makes the value actionable when there is one — a number to call, say. */
  href?: string
}

function Detail({ label, value, href }: DetailProps) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-3">
      <dt className="text-sm text-white/60">{label}</dt>
      <dd className="min-w-0 text-md">
        {value === null ? (
          <span className="text-white/45">Not recorded</span>
        ) : href ? (
          <a href={href} className="truncate text-white transition-colors hover:text-brand">
            {value}
          </a>
        ) : (
          <span className="truncate text-white">{value}</span>
        )}
      </dd>
    </div>
  )
}

interface StatProps {
  icon: LucideIcon
  label: string
  value: string
}

function Stat({ icon: Icon, label, value }: StatProps) {
  return (
    <div className="min-w-0">
      <dt className="flex items-center gap-1.5 text-2xs tracking-wide text-white/60 uppercase">
        <Icon size={13} aria-hidden className="shrink-0" />
        {label}
      </dt>
      <dd className="mt-1 truncate text-xl font-semibold tabular-nums text-white">{value}</dd>
    </div>
  )
}

ForemanShow.layout = appLayout
