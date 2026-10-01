import { useCallback, useEffect, useRef, useState } from 'react'
import { Head, Link, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import type { LucideIcon } from 'lucide-react'
import { ArrowLeft, Building2, FileText, FolderOpen, Hash, History, Layers, Layers2, Play, Ruler, Sparkles, TriangleAlert, UploadCloud } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  SelectField,
  TextArea,
  TextInput,
  UnfinishedTakeoffNotice,
} from '@/components/common'
import { PageHeader, PageTransition, StepWizard, appLayout } from '@/components/layout'
import { ProjectPickerCard, UploadDropzone, UploadedFileCard } from '@/components/upload'
import { ROUTES, routeTo } from '@/constants'
import { useFileUpload } from '@/hooks'
import { formatFileSize } from '@/utils'
import { selectHasValidFiles, useUploadStore } from '@/store'
import type {
  RejectedUploadFile,
  ResumableTakeoff,
  SharedPageProps,
  UploadLimits,
  UploadTargetProject,
} from '@/types'

export interface UploadProps {
  projects: readonly UploadTargetProject[]
  /**
   * The client the picker opens on — named by the link that got here, or the
   * one just created. Null when neither applies.
   */
  selectedProjectId: number | null
  limits: UploadLimits
  /** False when AI_API_BASE_URL is unset — a run cannot be started. */
  aiConfigured: boolean
  /**
   * A takeoff already part-way through, when it is not the one this screen
   * opened for. Sending a different client's drawing forks the flow.
   */
  unfinishedTakeoff: ResumableTakeoff | null
  /**
   * Polled for engine readiness. Deliberately not a prop: asking the engine during
   * a render would let a slow or missing engine hold the page up.
   */
  engineStatusUrl: string
  /** Set when this screen was opened from "Upload Addendum" on a standalone estimate. */
  addendumFor: {
    readonly estimateId: number
    readonly estimateNumber: string
    readonly projectName: string | null
    /** The addendum number this upload will become. */
    readonly nextNumber: number
  } | null
}

/**
 * Primary entry point, and the only place a drawing PDF enters the app: pick
 * the client, drop the file, run the takeoff. Nothing else is on the screen —
 * anything that is not one of those two steps is a distraction from them.
 *
 * The queue is local until "Run AI Takeoff", which posts the files to Laravel;
 * the server stores them, opens a takeoff run and redirects to its progress.
 */
export default function Upload({
  projects,
  selectedProjectId,
  limits,
  aiConfigured,
  unfinishedTakeoff,
  engineStatusUrl,
  addendumFor,
}: UploadProps) {
  /** `null` while unknown, so the screen never claims the engine is down too early. */
  const [engineOnline, setEngineOnline] = useState<boolean | null>(null)

  useEffect(() => {
    if (!aiConfigured) return

    let active = true

    const check = async () => {
      try {
        const response = await fetch(engineStatusUrl, {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        })
        const status = (await response.json()) as { online: boolean }

        if (active) setEngineOnline(status.online)
      } catch {
        if (active) setEngineOnline(false)
      }
    }

    void check()
    // Re-checked while the screen is open, so starting the engine clears the warning.
    const timer = window.setInterval(check, 15000)

    return () => {
      active = false
      window.clearInterval(timer)
    }
  }, [aiConfigured, engineStatusUrl])

  const { errors } = usePage<SharedPageProps>().props

  const {
    files,
    rejected,
    projectId,
    isSubmitting,
    formError,
    addFiles,
    replaceFile,
    setRejected,
    clearRejected,
    setProjectId,
    setFormError,
  } = useUploadStore()
  const hasValidFiles = useUploadStore(selectHasValidFiles)
  const { startUpload } = useFileUpload()
  const selectedProject = projects.find((project) => project.id === projectId) ?? null

  /*
   * Applied once, and only into an empty picker: arriving with a client in
   * mind should not silently retarget a queue already built against another.
   */
  const preselected = useRef(false)

  useEffect(() => {
    if (preselected.current || selectedProjectId === null) return

    preselected.current = true
    if (projectId === null) setProjectId(selectedProjectId)
    // `projectId` is read, not tracked: this must run on arrival, not again
    // every time the picker changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedProjectId, setProjectId])

  const handleRejected = useCallback(
    (items: RejectedUploadFile[]) => setRejected(items),
    [setRejected],
  )

  // A rejection from the server outranks whatever the client last reported.
  const alertMessage = errors['files'] ?? formError

  const notices = (
    <>
      <AnimatePresence initial={false}>
        {alertMessage && (
          <Alert
            key="form-error"
            tone="danger"
            title="Check your files"
            onDismiss={() => setFormError(null)}
          >
            {alertMessage}
          </Alert>
        )}

        {rejected.length > 0 && (
          <Alert
            key="rejected"
            tone="warning"
            title={`${rejected.length} file(s) could not be added`}
            icon={TriangleAlert}
            onDismiss={clearRejected}
          >
            <ul className="mt-1 space-y-1">
              {rejected.map((item) => (
                <li key={item.name} className="text-sm">
                  <span className="font-medium text-white">{item.name}</span> —{' '}
                  {item.reason}
                </li>
              ))}
            </ul>
          </Alert>
        )}
      </AnimatePresence>

      {!aiConfigured && (
        <Alert
          key="ai-not-configured"
          tone="warning"
          title="AI takeoff isn't available right now"
          icon={TriangleAlert}
        >
          Please contact support, or try again later. Drawings can't be submitted
          until this is resolved.
        </Alert>
      )}

      {aiConfigured && engineOnline === false && (
        <Alert
          key="ai-offline"
          tone="danger"
          title="AI takeoff is temporarily unavailable"
          icon={TriangleAlert}
        >
          We can't reach the AI takeoff service right now — a drawing uploaded now
          would fail analysis. Please try again shortly, or contact support if this
          continues.
        </Alert>
      )}

      {/* PHP's own upload ceiling, when it is the binding constraint. */}
      {limits.serverHint && (
        <Alert key="php-limit" tone="info" title="Upload size limit">
          This server accepts drawings up to {limits.maxFileSizeMb} MB. Contact
          support if you need to upload a larger set.
        </Alert>
      )}
    </>
  )

  const [reason, setReason] = useState('')
  const [sheets, setSheets] = useState('')

  if (addendumFor) {
    return (
      <PageTransition>
        <Head title="Upload Addendum" />

        <Card padding="none" className="overflow-hidden">
          <div className="p-5 sm:p-8">
            <Link
              href={
                projectId !== null ? routeTo.addendaForProject(projectId) : ROUTES.addenda
              }
              className="inline-flex items-center gap-2 text-md font-medium text-white transition-colors hover:text-brand"
            >
              <ArrowLeft size={17} aria-hidden />
              Addendum
            </Link>
            <h1 className="mt-3 text-3xl font-bold text-white">Upload Addendum</h1>
            <p className="mt-1 text-md text-white/85">
              Upload revised drawings and let Breeze analyze the changes to update your estimate.
            </p>

            <UnfinishedTakeoffNotice
              takeoff={unfinishedTakeoff}
              starting="another takeoff"
              className="mt-6"
            />

            <div className="mt-6 grid gap-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1.25fr)_minmax(0,0.8fr)]">
              <AddendumField icon={Building2} label="Project">
                <SelectField
                  id="addendum-project"
                  aria-label="Project"
                  options={[
                    { value: '', label: projects.length > 0 ? 'Select a project…' : 'No projects yet' },
                    ...projects.map((project) => ({
                      value: String(project.id),
                      label: project.clientName ? `${project.clientName} — ${project.name}` : project.name,
                    })),
                  ]}
                  value={projectId === null ? '' : String(projectId)}
                  onChange={(event) => setProjectId(event.target.value ? Number(event.target.value) : null)}
                  {...(errors['project_id'] ? { error: errors['project_id'] } : {})}
                />
              </AddendumField>

              {/* Fixed by the link that opened this screen — shown, not chosen. */}
              <AddendumField icon={FileText} label="Estimate">
                <SelectField
                  id="addendum-estimate"
                  aria-label="Estimate"
                  disabled
                  options={[
                    {
                      value: String(addendumFor.estimateId),
                      label: addendumFor.projectName
                        ? `${addendumFor.estimateNumber} — ${addendumFor.projectName}`
                        : addendumFor.estimateNumber,
                    },
                  ]}
                  value={String(addendumFor.estimateId)}
                />
              </AddendumField>

              <AddendumField icon={Hash} label="Addendum Number">
                <TextInput
                  id="addendum-number"
                  aria-label="Addendum Number"
                  disabled
                  value={String(addendumFor.nextNumber).padStart(2, '0')}
                  readOnly
                />
              </AddendumField>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]">
              <Card padding="md" className="flex flex-col">
                <div className="flex items-start gap-3">
                  <UploadCloud size={26} aria-hidden className="mt-0.5 shrink-0 text-brand" />
                  <div>
                    <h2 className="text-lg font-semibold text-white">Revised Drawings</h2>
                    <p className="text-xs text-white/80">
                      Drag and drop revised drawings here, or click to browse.
                    </p>
                  </div>
                </div>

                <div className="mt-4 flex-1 space-y-4">
                  {notices}
                  {files.length === 0 ? (
                    <UploadDropzone
                      onFilesAccepted={addFiles}
                      onFilesRejected={handleRejected}
                      disabled={isSubmitting}
                      hasError={Boolean(alertMessage)}
                      isSuccess={hasValidFiles}
                      maxFiles={limits.maxFiles}
                      maxFileSizeMb={limits.maxFileSizeMb}
                      className="min-h-64"
                    />
                  ) : (
                    files.map((file) => (
                      <UploadedFileCard
                        key={file.id}
                        file={file}
                        onReplace={replaceFile}
                        disabled={isSubmitting}
                      />
                    ))
                  )}
                </div>
              </Card>

              <div className="flex flex-col gap-4">
                <Card padding="md">
                  <div className="flex items-center gap-3">
                    <FileText size={22} aria-hidden className="shrink-0 text-white/90" />
                    <h2 className="text-md font-semibold text-white">Revision Reason</h2>
                  </div>
                  <TextArea
                    id="addendum-reason"
                    aria-label="Revision Reason"
                    rows={4}
                    maxLength={2000}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    placeholder="Describe the reason for this addendum..."
                    className="mt-3"
                  />
                </Card>

                <Card padding="md">
                  <div className="flex items-center gap-3">
                    <Layers2 size={22} aria-hidden className="shrink-0 text-white/90" />
                    <h2 className="text-md font-semibold text-white">Affected Sheets</h2>
                  </div>
                  <TextInput
                    id="addendum-sheets"
                    aria-label="Affected Sheets"
                    maxLength={500}
                    value={sheets}
                    onChange={(event) => setSheets(event.target.value)}
                    placeholder="e.g. A1.1, S2.0, M1.2 (comma separated)"
                    className="mt-3"
                  />
                  <p className="mt-2 text-xs text-white/75">
                    List the drawing sheets that were revised or affected by this addendum.
                  </p>
                </Card>

                <Button
                  size="lg"
                  fullWidth
                  leftIcon={Sparkles}
                  isLoading={isSubmitting}
                  disabled={files.length === 0}
                  onClick={() =>
                    startUpload({ addendum_reason: reason, affected_sheets: sheets })
                  }
                >
                  Upload and Analyze
                </Button>
              </div>
            </div>
          </div>
        </Card>
      </PageTransition>
    )
  }

  return (
    <PageTransition>
      <Head title="AI Takeoff Upload" />

      <StepWizard current="upload" />

      <PageHeader
        title="Upload Drawings"
        subtitle="Upload your project drawings to begin Ai-Powered Takeoff and Estimate Generation"
        breadcrumbs={[{ label: 'AI Takeoff', href: ROUTES.aiTakeoff }, { label: 'Upload Drawings' }]}
        actions={
          <ButtonLink href={ROUTES.history} variant="secondary" leftIcon={History}>
            History
          </ButtonLink>
        }
      />

      <UnfinishedTakeoffNotice
        takeoff={unfinishedTakeoff}
        starting="another takeoff"
        className="mb-6"
      />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <Card padding="md">
          <div className="space-y-4">
            <ProjectPickerCard
              bare
              projects={projects}
              value={projectId}
              onChange={setProjectId}
              {...(errors['project_id'] ? { error: errors['project_id'] } : {})}
            />

            {notices}

            {files.length === 0 ? (
              <UploadDropzone
                onFilesAccepted={addFiles}
                onFilesRejected={handleRejected}
                disabled={isSubmitting}
                hasError={Boolean(alertMessage)}
                isSuccess={hasValidFiles}
                maxFiles={limits.maxFiles}
                maxFileSizeMb={limits.maxFileSizeMb}
              />
            ) : (
              files.map((file) => (
                <UploadedFileCard
                  key={file.id}
                  file={file}
                  onReplace={replaceFile}
                  disabled={isSubmitting}
                />
              ))
            )}
          </div>
        </Card>

        <Card padding="md">
          <CardHeader title="Upload Summary" />
          <dl className="mt-4 space-y-4 text-md">
            <SummaryRow
              icon={FolderOpen}
              label="Project"
              value={selectedProject ? selectedProject.name : 'Not selected'}
            />
            <SummaryRow icon={Layers} label="Files queued" value={String(files.length)} />
            <SummaryRow
              icon={Ruler}
              label="Total size"
              value={formatFileSize(files.reduce((total, file) => total + file.size, 0))}
            />
            <SummaryRow
              icon={Play}
              label="Limits"
              value={`${limits.maxFiles === 1 ? '1 file' : `Up to ${limits.maxFiles} files`} · ${limits.maxFileSizeMb} MB each`}
            />
          </dl>
        </Card>
      </div>

      {files.length > 0 && (
        <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-white/80">
            {files.length} {files.length === 1 ? 'file' : 'files'} ready for AI takeoff
          </p>
          <Button size="lg" leftIcon={Play} isLoading={isSubmitting} onClick={() => startUpload()}>
            Start AI Takeoff
          </Button>
        </div>
      )}
    </PageTransition>
  )
}

function AddendumField({
  icon: Icon,
  label,
  children,
}: {
  icon: LucideIcon
  label: string
  children: React.ReactNode
}) {
  return (
    <Card padding="md">
      <div className="mb-3 flex items-center gap-3">
        <Icon size={22} aria-hidden className="shrink-0 text-white/90" />
        <h2 className="text-md font-semibold text-white">{label}</h2>
      </div>
      {children}
    </Card>
  )
}

function SummaryRow({
  icon: Icon,
  label,
  value,
}: {
  icon: LucideIcon
  label: string
  value: string
}) {
  return (
    <div className="flex items-center gap-3">
      <span className="grid size-9 shrink-0 place-items-center rounded-panel bg-brand/15 text-brand ring-1 ring-brand/30">
        <Icon size={16} aria-hidden />
      </span>
      <div className="min-w-0">
        <dt className="text-xs text-white/70">{label}</dt>
        <dd className="truncate font-medium text-white">{value}</dd>
      </div>
    </div>
  )
}

Upload.layout = appLayout
