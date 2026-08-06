import { useCallback, useEffect, useState } from 'react'
import { Head, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { History, Sparkles, Trash2, TriangleAlert } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  ConfirmDialog,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  AiFeaturesCard,
  HelpCard,
  ProjectNotesCard,
  RecentUploadCard,
  UploadDropzone,
  UploadFileList,
} from '@/components/upload'
import { HELP_RESOURCES, ROUTES } from '@/constants'
import { useDisclosure, useFileUpload } from '@/hooks'
import { selectHasValidFiles, useUploadStore } from '@/store'
import type {
  RecentUpload,
  RejectedUploadFile,
  SharedPageProps,
  UploadLimits,
} from '@/types'

export interface UploadProps {
  recentUploads: readonly RecentUpload[]
  limits: UploadLimits
  /** False when AI_API_BASE_URL is unset — a run cannot be started. */
  aiConfigured: boolean
  /**
   * Polled for engine readiness. Deliberately not a prop: asking the engine during
   * a render would let a slow or missing engine hold the page up.
   */
  engineStatusUrl: string
}

/**
 * Primary entry point: drag & drop upload, project notes, capability summary,
 * recent activity and help resources.
 *
 * The queue is local until "Run AI Takeoff", which posts the files to Laravel;
 * the server stores them, opens a takeoff run and redirects to its progress.
 */
export default function Upload({
  recentUploads,
  limits,
  aiConfigured,
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
    notes,
    isSubmitting,
    formError,
    addFiles,
    removeFile,
    replaceFile,
    setRejected,
    clearRejected,
    setNotes,
    setFormError,
    reset,
  } = useUploadStore()
  const hasValidFiles = useUploadStore(selectHasValidFiles)
  const { startUpload } = useFileUpload()
  const clearDialog = useDisclosure()

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

      <PageHeader
        title="AI Takeoff Upload"
        subtitle="Upload your project files for AI-powered electrical takeoff analysis."
        breadcrumbs={[{ label: 'AI Takeoff', href: ROUTES.upload }, { label: 'Upload' }]}
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

      {/* Upload + context grid */}
      <div className="grid gap-6 xl:grid-cols-2">
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
                title="AI takeoff engine not configured"
                icon={TriangleAlert}
              >
                Set <code>AI_API_BASE_URL</code> in <code>.env</code> so uploads can be
                analysed. Drawings will not be submitted until it is set.
              </Alert>
            )}

            {aiConfigured && engineOnline === false && (
              <Alert
                key="ai-offline"
                tone="danger"
                title="The AI takeoff engine is not responding"
                icon={TriangleAlert}
              >
                Nothing answered at <code>{'/api/health'}</code>. Start the engine, then
                reload — a drawing uploaded now would fail analysis.
              </Alert>
            )}

            {/* PHP's own upload ceiling, when it is the binding constraint. */}
            {limits.serverHint && (
              <Alert key="php-limit" tone="info" title="Server upload limit">
                {limits.serverHint}
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

        <div className="flex flex-col gap-6">
          <ProjectNotesCard
            value={notes}
            onChange={setNotes}
            {...(errors['notes'] ? { error: errors['notes'] } : {})}
            index={1}
          />
          <AiFeaturesCard index={2} />
        </div>
      </div>

      {/* Recent activity */}
      <Card padding="lg" className="mt-6" index={1}>
        <CardHeader
          title="Recent Activity"
          subtitle={`Your ${recentUploads.length} most recent uploads`}
          actions={
            <ButtonLink href={ROUTES.results} variant="dark" size="sm">
              View last result
            </ButtonLink>
          }
        />

        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {recentUploads.map((upload, index) => (
            <RecentUploadCard key={upload.id} upload={upload} index={index} />
          ))}
        </div>
      </Card>

      {/* Help */}
      <Card padding="lg" className="mt-6" index={2}>
        <CardHeader
          title="Need Help?"
          subtitle="Guides, walkthroughs and a human when you need one"
        />

        <div className="grid gap-5 lg:grid-cols-3">
          {HELP_RESOURCES.map((resource, index) => (
            <HelpCard key={resource.id} resource={resource} index={index} />
          ))}
        </div>
      </Card>

      <ConfirmDialog
        isOpen={clearDialog.isOpen}
        tone="danger"
        title="Remove all queued files?"
        description="This clears the upload queue and your project notes. It cannot be undone."
        confirmLabel="Clear everything"
        confirmVariant="danger"
        onConfirm={handleClearAll}
        onCancel={clearDialog.close}
      />
    </PageTransition>
  )
}

Upload.layout = appLayout
