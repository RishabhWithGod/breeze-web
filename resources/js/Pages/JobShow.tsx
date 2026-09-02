import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  Archive,
  ArrowLeft,
  Building2,
  CalendarDays,
  Check,
  HardHat,
  MapPin,
  PencilLine,
  Plus,
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
  SectionHeading,
  SelectField,
  StatusChip,
} from '@/components/common'
import {
  ForemanBadge,
  JobAttachmentsPanel,
  JobEstimatesPanel,
  JobNotesPanel,
  JobTakeoffPanel,
  JobTasksPanel,
} from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { JOB_STATUS_OPTIONS, ROUTES, routeTo } from '@/constants'
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
}

/**
 * Job detail — the hub of the job management module.
 *
 * Every panel writes through its own controller and the page reloads with the
 * updated relationships, so what is on screen always matches the database.
 */
export default function JobShow({ job, canPlanWork }: JobShowProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const deleteDialog = useDisclosure()

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  const changeStatus = (status: JobStatus) => {
    router.post(routeTo.jobStatus(job.id), { status }, { preserveScroll: true })
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
        subtitle={[job.client, job.location].filter(Boolean).join(' · ') || undefined}
        breadcrumbs={[{ label: 'Jobs', href: ROUTES.jobs }, { label: 'Details' }]}
        actions={
          <>
            <ButtonLink href={ROUTES.jobs} variant="secondary" leftIcon={ArrowLeft}>
              Back
            </ButtonLink>
            <ButtonLink
              href={routeTo.jobEdit(job.id)}
              variant="dark"
              leftIcon={PencilLine}
            >
              Edit
            </ButtonLink>
            {/*
              Every panel on this page writes through its own controller and saves
              immediately, so there is nothing to submit here — this just closes out
              the setup and sends the user back to the list.
            */}
            <ButtonLink href={ROUTES.jobs} leftIcon={Check}>
              Finish
            </ButtonLink>
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
      <Card padding="lg">
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

            <div className="mt-5 flex items-center gap-3">
              <span className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                <HardHat size={13} aria-hidden className="text-brand" />
                Foreman
              </span>
              {job.foreman ? (
                <ForemanBadge foreman={job.foreman} />
              ) : (
                <span className="text-md text-white/70">Unassigned</span>
              )}
            </div>

            {job.description && (
              <p className="mt-5 max-w-3xl text-md whitespace-pre-line text-white/90">
                {job.description}
              </p>
            )}
          </div>

          {/* Actions */}
          <div className="flex w-full flex-col gap-3 xl:w-64">
            <SelectField
              id="job-status-select"
              label="Status"
              options={JOB_STATUS_OPTIONS}
              value={job.status}
              onChange={(event) => changeStatus(event.target.value as JobStatus)}
            />

            <Button variant="danger" leftIcon={Trash2} onClick={deleteDialog.open}>
              Delete
            </Button>
          </div>
        </div>
      </Card>

      {job.takeoff && (
        <div className="mt-6">
          <JobTakeoffPanel takeoff={job.takeoff} />
        </div>
      )}

      {/* ======================================================= Tasks ======= */}
      <Card padding="lg" className="mt-6">
        <SectionHeading
          title="Tasks"
          subtitle={`${job.tasks.length} on this job`}
          actions={
            /*
             * The same step that laid the job out in the first place, aimed at
             * this job — its estimate lines, its foremen, and what is already
             * planned listed above the new rows.
             */
            canPlanWork ? (
              <ButtonLink
                href={routeTo.jobTaskSetupFromJob(job.id)}
                variant="secondary"
                size="sm"
                leftIcon={Plus}
              >
                Add task
              </ButtonLink>
            ) : undefined
          }
        />
        <JobTasksPanel tasks={job.tasks} canPlan={canPlanWork} />
      </Card>

      {/* =================================================== Estimates ======= */}
      <Card padding="lg" className="mt-6">
        <SectionHeading
          title="Estimates"
          subtitle={`${job.estimates.length} raised for this job`}
        />
        <JobEstimatesPanel jobId={job.id} estimates={job.estimates} />
      </Card>

      {/* =============================================== Notes + files ======= */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card padding="lg">
          <SectionHeading title="Notes" subtitle={`${job.notes.length} recorded`} />
          <JobNotesPanel jobId={job.id} notes={job.notes} />
        </Card>

        <Card padding="lg">
          <SectionHeading
            title="Attachments"
            subtitle={`${job.attachments.length} uploaded`}
          />
          <JobAttachmentsPanel jobId={job.id} attachments={job.attachments} />
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
