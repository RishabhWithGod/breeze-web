import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  Archive,
  ArrowLeft,
  Building2,
  CalendarDays,
  MapPin,
  PencilLine,
  Plus,
  Receipt,
  Trash2,
  Wallet,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  IconButton,
  SectionHeading,
  SelectField,
  StatusChip,
} from '@/components/common'
import {
  JobAttachmentsPanel,
  JobEstimatesPanel,
  JobNotesPanel,
  JobTakeoffPanel,
  JobTaskFieldNotesPanel,
  JobTasksPanel,
} from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { JOB_STATUS_OPTIONS, ROUTES, routeTo } from '@/constants'
import type { JobOrigin } from '@/constants'
import { useDisclosure, useEchoConnectionState, usePrivateChannel } from '@/hooks'
import type {
  JobCostRow,
  JobDetail,
  JobStatus,
  SharedPageProps,
} from '@/types'
import {
  JOB_STATUS_LABEL,
  JOB_STATUS_TONE,
  JOB_TYPE_LABEL,
  formatCurrency,
  formatDate,
} from '@/utils'

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
  canPlanWork,
  canManageApprentices,
  canCreateInvoice,
  apprenticeAssignments,
  back,
  from,
}: JobShowProps) {
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

  return (
    <PageTransition>
      <Head title={job.name} />

      <PageHeader
        title={job.name}
        // The crew is named up here with the client and the site: it is what
        // decides who the job's tasks can be given to.
        subtitle={
          [job.client, job.location, job.teamName].filter(Boolean).join(' · ') || undefined
        }
        breadcrumbs={[{ label: 'Jobs', href: ROUTES.jobs }, { label: 'Details' }]}
        actions={
          <>
            <ButtonLink href={back.url} variant="secondary" leftIcon={ArrowLeft}>
              {back.label}
            </ButtonLink>
            {!isLocked && (
              <ButtonLink
                href={routeTo.jobEditFrom(job.id, from)}
                variant="dark"
                leftIcon={PencilLine}
              >
                Edit
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
          </>
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

      {/* ================================================= Summary bar ======= */}
      <Card accent="brand" padding="lg">
        <div className="flex flex-wrap items-start justify-between gap-5">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-3">
              <StatusChip
                tone={JOB_STATUS_TONE[job.status]}
                label={JOB_STATUS_LABEL[job.status]}
                pulse={job.status === 'in-progress'}
              />
              {job.isArchived && (
                <Badge tone="warning" icon={Archive}>
                  Archived
                </Badge>
              )}
              {job.jobType && <Badge tone="info">{JOB_TYPE_LABEL[job.jobType]}</Badge>}
            </div>

            <dl className="mt-5 grid gap-x-8 gap-y-4 sm:grid-cols-2 xl:grid-cols-4">
              <div>
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <Building2 size={13} aria-hidden className="text-brand" />
                  Client
                </dt>
                <dd className="mt-1.5 text-md text-white">{job.client ?? '—'}</dd>
              </div>
              <div>
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <MapPin size={13} aria-hidden className="text-brand" />
                  Location
                </dt>
                <dd className="mt-1.5 text-md text-white">{job.location ?? '—'}</dd>
              </div>
              <div>
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <CalendarDays size={13} aria-hidden className="text-brand" />
                  Schedule
                </dt>
                <dd className="mt-1.5 text-md text-white">
                  {job.startDate ? formatDate(job.startDate) : '—'}
                  <span className="mx-2 text-white/60">→</span>
                  {job.endDate ? formatDate(job.endDate) : '—'}
                </dd>
              </div>
              <div>
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <Wallet size={13} aria-hidden className="text-brand" />
                  Budget
                </dt>
                <dd className="mt-1.5 text-md font-semibold tabular-nums text-white">
                  {job.budget === null ? '—' : formatCurrency(job.budget, 2)}
                </dd>
              </div>
            </dl>

            {job.description && (
              <div className="mt-5 max-w-3xl border-t border-hairline pt-4">
                <p className="text-2xs tracking-wide text-white/70 uppercase">
                  Description
                </p>
                <p className="mt-1 text-md whitespace-pre-line text-white/90">
                  {job.description}
                </p>
              </div>
            )}
          </div>

          {/* Actions */}
          <div className="flex w-full flex-col gap-3 xl:w-64">
            <SelectField
              id="job-status-select"
              label="Status"
              options={JOB_STATUS_OPTIONS}
              value={job.status}
              disabled={isLocked}
              onChange={(event) => changeStatus(event.target.value as JobStatus)}
            />

            {!isLocked && (
              <Button variant="danger" leftIcon={Trash2} onClick={deleteDialog.open}>
                Delete
              </Button>
            )}
          </div>
        </div>
      </Card>

      {job.takeoff && (
        <div className="mt-6">
          <JobTakeoffPanel takeoff={job.takeoff} />
        </div>
      )}

      {/* ======================================================= Tasks ======= */}
      <Card accent="success" padding="lg" className="mt-6">
        <SectionHeading
          title="Tasks"
          subtitle={`${job.tasks.length} on this job`}
          actions={
            /*
             * The same step that laid the job out in the first place, aimed at
             * this job — its estimate lines, its foremen, and what is already
             * planned listed above the new rows.
             */
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
      </Card>

      {/*
        ================================================= Apprentices =======
        Read/manage only: a foreman makes the actual assignment from the
        mobile app's Job Detail screen, not from here.
      */}
      <Card accent="info" padding="lg" className="mt-6">
        <SectionHeading
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

      {/* ============================================ Field notes & photos ======= */}
      {job.tasks.some(
        (task) =>
          task.comments.length > 0 ||
          task.attachments.length > 0 ||
          task.materialLines.some(
            (line) => line.comments.length > 0 || line.attachments.length > 0,
          ),
      ) && (
        <Card accent="info" padding="lg" className="mt-6">
          <SectionHeading
            title="Field Notes & Photos"
            subtitle="Left by the crew, from the mobile app — by task, then by material"
          />
          <JobTaskFieldNotesPanel tasks={job.tasks} />
        </Card>
      )}

      {/* =================================================== Estimates ======= */}
      <Card accent="warning" padding="lg" className="mt-6">
        <SectionHeading
          title="Estimates"
          subtitle={`${job.estimates.length} raised for this job`}
        />
        <JobEstimatesPanel jobId={job.id} estimates={job.estimates} />
      </Card>

      {/* =============================================== Notes + files ======= */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card accent="neutral" padding="lg">
          <SectionHeading title="Notes" subtitle={`${job.notes.length} recorded`} />
          <JobNotesPanel jobId={job.id} notes={job.notes} readOnly={isLocked} />
        </Card>

        <Card accent="brand" padding="lg">
          <SectionHeading
            title="Attachments"
            subtitle={`${job.attachments.length} uploaded`}
          />
          <JobAttachmentsPanel jobId={job.id} attachments={job.attachments} readOnly={isLocked} />
        </Card>
      </div>

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

JobShow.layout = appLayout
