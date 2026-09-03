import { useCallback, useEffect, useRef, useState } from 'react'
import { Head, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { History, Sparkles, Trash2, TriangleAlert } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  UnfinishedTakeoffNotice,
} from '@/components/common'
import { PageHeader, PageTransition, StepWizard, appLayout } from '@/components/layout'
import { ProjectPickerCard, UploadDropzone, UploadFileList } from '@/components/upload'
import { ROUTES } from '@/constants'
import { useDisclosure, useFileUpload } from '@/hooks'
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
    removeFile,
    replaceFile,
    setRejected,
    clearRejected,
    setProjectId,
    setFormError,
    reset,
  } = useUploadStore()
  const hasValidFiles = useUploadStore(selectHasValidFiles)
  const { startUpload } = useFileUpload()
  const clearDialog = useDisclosure()

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

  const handleClearAll = () => {
    reset()
    clearDialog.close()
  }

  // A rejection from the server outranks whatever the client last reported.
  const alertMessage = errors['files'] ?? formError

  return (
    <PageTransition>
      <Head title="AI Takeoff Upload" />

      <StepWizard current="upload" />

      <PageHeader
        title="AI Takeoff Upload"
        subtitle="Upload your client files for AI-powered electrical takeoff analysis."
        breadcrumbs={[{ label: 'AI Takeoff', href: ROUTES.aiTakeoff }, { label: 'Upload' }]}
        actions={
          <>
            <ButtonLink href={ROUTES.history} variant="secondary" leftIcon={History}>
              History
            </ButtonLink>
            <Button
              variant="dark"
              leftIcon={Sparkles}
              isLoading={isSubmitting}
              onClick={startUpload}
            >
              Run AI Takeoff
            </Button>
          </>
        }
      />

      <UnfinishedTakeoffNotice
        takeoff={unfinishedTakeoff}
        starting="another takeoff"
        className="mb-6"
      />

      <ProjectPickerCard
        projects={projects}
        value={projectId}
        onChange={setProjectId}
        {...(errors['project_id'] ? { error: errors['project_id'] } : {})}
      />

      <div className="mt-6">
        <Card padding="lg" className="flex flex-col justify-center">
          <div className="space-y-4">
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

            <UploadDropzone
              onFilesAccepted={addFiles}
              onFilesRejected={handleRejected}
              disabled={isSubmitting}
              hasError={Boolean(alertMessage)}
              isSuccess={hasValidFiles}
              maxFiles={limits.maxFiles}
              maxFileSizeMb={limits.maxFileSizeMb}
            />

            <UploadFileList
              files={files}
              onRemove={removeFile}
              onReplace={replaceFile}
              disabled={isSubmitting}
            />

            {files.length > 0 && (
              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-4">
                <Button
                  variant="ghost"
                  size="sm"
                  leftIcon={Trash2}
                  onClick={clearDialog.open}
                  disabled={isSubmitting}
                >
                  Clear all
                </Button>
                <Button
                  size="sm"
                  leftIcon={Sparkles}
                  isLoading={isSubmitting}
                  onClick={startUpload}
                >
                  Start analysis
                </Button>
              </div>
            )}
          </div>
        </Card>
      </div>

      <ConfirmDialog
        isOpen={clearDialog.isOpen}
        tone="danger"
        title="Remove all queued files?"
        description="This clears the upload queue. It cannot be undone."
        confirmLabel="Clear everything"
        confirmVariant="danger"
        onConfirm={handleClearAll}
        onCancel={clearDialog.close}
      />
    </PageTransition>
  )
}

Upload.layout = appLayout
