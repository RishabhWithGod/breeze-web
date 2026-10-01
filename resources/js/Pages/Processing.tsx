import { useCallback, useEffect, useRef, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import type { LucideIcon } from 'lucide-react'
import { ArrowLeft, ArrowRight, Ban, Clock, FileText, Layers, LoaderCircle, RotateCcw, Tag } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
} from '@/components/common'
import { PageTransition, StepWizard, appLayout } from '@/components/layout'
import { ProcessingStepList } from '@/components/processing'
import { ROUTES, routeTo } from '@/constants'
import {
  useCreepingProgress,
  useDisclosure,
  useEchoConnectionState,
  usePrivateChannel,
} from '@/hooks'
import { useUploadStore } from '@/store'
import { cn } from '@/utils'
import type {
  ProcessingStage,
  ProcessingStageState,
  RecentUpload,
  RunState,
  TakeoffHistoryRow,
} from '@/types'

export interface ProcessingProps {
  project: TakeoffHistoryRow
  uploads: readonly RecentUpload[]
  /** Pipeline definition from config/takeoff.php. */
  stages: readonly ProcessingStage[]
  /** State of the AI run when the page was rendered. */
  run: RunState
  pollIntervalMs: number
}

/**
 * Maps the run's reported progress onto the pipeline stage list.
 *
 * The AI service reports a percentage and a free-text stage name; the checklist
 * is a coarse view of that single number, so the stage the service names is shown
 * as the headline and treated as authoritative.
 */
function stageStatesFor(
  stages: readonly ProcessingStage[],
  progress: number,
  status: RunState['status'],
): readonly ProcessingStageState[] {
  const reached = Math.floor((progress / 100) * stages.length)

  return stages.map((stage, index) => {
    if (status === 'failed' || status === 'cancelled') {
      return { ...stage, status: index === reached ? 'failed' : index < reached ? 'complete' : 'pending' }
    }

    if (status === 'succeeded' || progress >= 100) {
      return { ...stage, status: 'complete' }
    }

    return {
      ...stage,
      status: index < reached ? 'complete' : index === reached ? 'active' : 'pending',
    }
  })
}

/**
 * Live view of a run in flight.
 *
 * Progress is polled from Laravel, which holds whatever the AI service last
 * reported. The engine only reports a handful of milestones with a long real
 * gap between them, so what's displayed is smoothed between those points by
 * `useCreepingProgress` rather than sitting frozen; it still never claims to
 * be further along than the server has actually confirmed. When the response
 * has been ingested the screen moves on to the AI Review screen automatically.
 */
export default function Processing({
  project,
  uploads,
  stages,
  run: initialRun,
  pollIntervalMs,
}: ProcessingProps) {
  const cancelDialog = useDisclosure()
  const resetQueue = useUploadStore((state) => state.reset)
  const [run, setRun] = useState<RunState>(initialRun)
  // Guards against a second navigation while the first is in flight.
  const navigated = useRef(false)

  /**
   * The realtime path: `AiTakeoffStatusChanged` broadcasts the exact same
   * shape `AiRunStatePresenter` builds for the long-poll response below, so
   * a pushed event can be applied to `run` directly — no separate payload
   * shape to keep in sync. This is the primary path; the long-poll chain
   * below still runs underneath it as the fallback for a connection that
   * never opens or drops, and a stale/duplicate push that repeats the same
   * state is harmless since React only re-renders on an actual change.
   */
  usePrivateChannel(!run.finished ? `project.${project.id}` : null, {
    'ai-takeoff.status-changed': (payload) => setRun(payload as unknown as RunState),
  })

  useEchoConnectionState(
    !run.finished
      ? () => {
          void fetch(`${routeTo.processingStatus(project.id)}?since=`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
          })
            .then((response) => (response.ok ? response.json() : null))
            .then((next) => next && setRun(next as RunState))
        }
      : undefined,
  )

  /**
   * Watches the run.
   *
   * Each request carries the state signature the screen is currently showing.
   * The server holds the request until that signature changes, so a minute-long
   * analysis costs a handful of requests instead of one every couple of seconds,
   * and a change shows up as soon as it happens rather than on the next tick.
   *
   * Chained rather than on an interval: with the server doing the waiting, a
   * fixed interval would stack overlapping requests.
   */
  useEffect(() => {
    if (run.finished) return

    let active = true
    let timer = 0

    const poll = async (since: string) => {
      if (!active) return

      try {
        const url = `${routeTo.processingStatus(project.id)}?since=${encodeURIComponent(since)}`
        const response = await fetch(url, {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        })

        if (!active) return

        if (!response.ok) {
          timer = window.setTimeout(() => void poll(since), pollIntervalMs)
          return
        }

        const next = (await response.json()) as RunState

        if (!active) return

        setRun(next)

        if (!next.finished) {
          timer = window.setTimeout(() => void poll(next.signature ?? ''), pollIntervalMs)
        }
      } catch {
        // A dropped poll is not fatal — try again after the usual gap.
        if (active) timer = window.setTimeout(() => void poll(since), pollIntervalMs)
      }
    }

    void poll(run.signature ?? '')

    return () => {
      active = false
      window.clearTimeout(timer)
    }
    // Deliberately not re-run on every `run` change: the chain drives itself, and
    // re-running here would start a second one on each update.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [project.id, pollIntervalMs, run.finished])

  /** Opens the review screen as soon as the detections exist. */
  useEffect(() => {
    if (run.status !== 'succeeded' || !run.reviewUrl || navigated.current) return

    navigated.current = true
    resetQueue()
    router.visit(run.reviewUrl)
  }, [run.status, run.reviewUrl, resetQueue])

  const handleCancel = useCallback(() => {
    cancelDialog.close()
    router.post(routeTo.processingCancel(project.id), {}, { preserveScroll: true })
  }, [cancelDialog, project.id])

  const handleRestart = useCallback(() => {
    router.post(routeTo.processingRestart(project.id), {}, { preserveScroll: true })
  }, [project.id])

  /** Queues the analysis again for a run no worker ever picked up. */
  const handleRetry = useCallback(() => {
    router.post(routeTo.processingRetry(project.id), {}, { preserveScroll: true })
  }, [project.id])

  const handleStartOver = useCallback(() => {
    resetQueue()
    router.visit(ROUTES.upload)
  }, [resetQueue])

  const isFailed = run.status === 'failed' || run.status === 'missing'
  const isCancelled = run.status === 'cancelled'
  const isDone = run.status === 'succeeded'
  const isRunning = !isFailed && !isCancelled && !isDone
  const displayProgress = useCreepingProgress(run.progress, isRunning)
  const stageStates = stageStatesFor(stages, displayProgress, run.status)
  const reachedIndex = stageStates.findIndex((stage) => stage.status === 'active')

  const elapsed = useElapsed(run.submittedAt ?? null, isRunning)
  const headline = isDone
    ? 'Detections are ready to review'
    : isFailed || isCancelled
      ? 'Nothing was written to your takeoff'
      : `${run.stageLabel ?? stageStates[Math.max(reachedIndex, 0)]?.label ?? 'Analyzing drawings'}…`
  const stageNumber = isDone ? stages.length : Math.min(Math.max(reachedIndex, 0) + 1, stages.length)
  const fileName = uploads[0]?.name ?? project.drawingName ?? 'Drawing'
  const tone = isFailed || isCancelled ? 'danger' : isDone ? 'success' : 'brand'

  return (
    <PageTransition>
      <Head title="AI Takeoff Processing" />

      <StepWizard current="analysis" hrefs={{ upload: ROUTES.upload }} />

      <Card padding="none" className="overflow-hidden">
        <div className="border-b border-hairline px-6 py-5 sm:px-8">
          <h1 className="text-2xl font-bold text-white sm:text-3xl">Breeze Takeoff Processing</h1>
          <p className="mt-1 text-md text-white/90">
            {uploads.length > 1
              ? `Our AI is analyzing ${uploads.length} drawings from ${project.name}`
              : 'Our AI is analyzing your drawings and extracting takeoff data'}
          </p>
        </div>

        <div className="p-4 sm:p-6">
          <div className="rounded-card border border-hairline bg-white/3 p-4 sm:p-6">
            <div className="grid gap-6 lg:grid-cols-[minmax(0,17rem)_minmax(0,1fr)] xl:grid-cols-[minmax(0,21rem)_minmax(0,1fr)]">
              {/* No page image exists until the run returns — a drawing-sheet stand-in. */}
              <div className="blueprint-grid relative grid aspect-4/3 place-items-center overflow-hidden rounded-panel border border-hairline-strong bg-navy-900/70">
                <FileText size={56} aria-hidden className="text-brand/70" />
                {isRunning && (
                  <span
                    className="pointer-events-none absolute inset-x-0 top-0 h-10 animate-scan bg-linear-to-b from-brand/30 to-transparent"
                    aria-hidden
                  />
                )}
                <span className="absolute right-2 bottom-2 rounded-sm bg-navy-950/80 px-2 py-0.5 text-2xs font-semibold text-white/85">
                  {(uploads[0]?.format ?? project.format ?? 'PDF').toString().toUpperCase()}
                </span>
              </div>

              <div className="min-w-0">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="truncate text-xl font-bold text-white sm:text-2xl">{project.name}</h2>
                    <p className="mt-1 truncate text-md text-white/75">{fileName}</p>
                  </div>
                  <StatusPill
                    tone={tone}
                    isRunning={isRunning}
                    label={
                      isFailed ? 'Failed' : isCancelled ? 'Cancelled' : isDone ? 'Completed' : 'Processing'
                    }
                  />
                </div>

                <div className="mt-5 flex items-center gap-4">
                  <div
                    className="h-3 flex-1 overflow-hidden rounded-full bg-white/10"
                    role="progressbar"
                    aria-valuenow={Math.round(displayProgress)}
                    aria-valuemin={0}
                    aria-valuemax={100}
                  >
                    <div
                      className={cn(
                        'h-full rounded-full transition-[width] duration-700',
                        tone === 'danger'
                          ? 'bg-status-danger'
                          : tone === 'success'
                            ? 'bg-status-success'
                            : 'bg-brand',
                      )}
                      style={{ width: `${Math.min(Math.max(displayProgress, 0), 100)}%` }}
                    />
                  </div>
                  <span className="w-12 text-right text-lg font-bold text-white">
                    {Math.round(displayProgress)}%
                  </span>
                </div>
                <p className="mt-3 text-md text-white/90">{headline}</p>

                <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                  <MetricTile icon={FileText} value={String(project.pageCount)} label="Pages" />
                  <MetricTile icon={Tag} value={String(uploads.length || 1)} label={uploads.length === 1 ? 'File' : 'Files'} />
                  <MetricTile icon={Layers} value={`${stageNumber}/${stages.length}`} label="Stage" />
                  <MetricTile icon={Clock} value={elapsed} label="Elapsed" />
                </div>
              </div>
            </div>

            <div className="mt-6 border-t border-hairline pt-5">
              <h3 className="mb-4 text-md font-semibold text-white">Current Status</h3>
              <ProcessingStepList stages={stageStates} className="max-w-md" />

              {run.processingTime && (
                <p className="mt-3 font-mono text-2xs text-white/65">{run.processingTime.toFixed(1)}s</p>
              )}

              {(isFailed || isCancelled || isDone) && (
                <p className="mt-4 max-w-2xl text-md text-white/90">
                  {isDone
                    ? 'Every detected symbol is waiting for review. Nothing reaches an estimate until you approve it.'
                    : isFailed
                      ? (run.error ?? 'The AI service did not return a usable response.')
                      : 'The run was cancelled and the uploaded drawing was removed. Upload it again when you are ready.'}
                </p>
              )}
            </div>
          </div>

          {/*
            A queued run that nothing has claimed means no queue worker is running.
            Said plainly, with the one action that fixes it, rather than spinning.
          */}
          {run.awaitingWorker && isRunning && (
            <Alert tone="warning" className="mt-6 text-left" title="This is taking longer than usual">
              <p>
                Your drawing is saved and queued for analysis, but it hasn't started yet.
                This screen will update automatically once it does — try again if it
                doesn't move shortly.
              </p>
              <Button
                variant="secondary"
                size="sm"
                className="mt-3"
                leftIcon={RotateCcw}
                onClick={handleRetry}
              >
                Queue it again
              </Button>
            </Alert>
          )}

          <div className="mt-6 flex flex-wrap items-center justify-end gap-3">
            {isRunning && (
              <Button variant="ghost" leftIcon={Ban} onClick={cancelDialog.open}>
                Cancel run
              </Button>
            )}
            {isDone && (
              <>
                <Button variant="secondary" leftIcon={RotateCcw} onClick={handleStartOver}>
                  New takeoff
                </Button>
                <Button
                  rightIcon={ArrowRight}
                  onClick={() => run.reviewUrl && router.visit(run.reviewUrl)}
                >
                  Continue to review
                </Button>
              </>
            )}
            {(isFailed || isCancelled) && (
              <>
                {/* Cancelling removes the drawing (see ProcessingController::cancel)
                    — there is nothing left to resubmit, only a genuine failure
                    leaves the file in place to retry against. */}
                {isFailed && (
                  <Button variant="secondary" leftIcon={RotateCcw} onClick={handleRestart}>
                    Resubmit drawing
                  </Button>
                )}
                <Button variant="outline" onClick={handleStartOver}>
                  Back to upload
                </Button>
              </>
            )}
            {isRunning && (
              <ButtonLink href={ROUTES.aiTakeoff} variant="outline" size="lg" leftIcon={ArrowLeft}>
                Continue Working
              </ButtonLink>
            )}
          </div>
        </div>
      </Card>

      <ConfirmDialog
        isOpen={cancelDialog.isOpen}
        tone="danger"
        title="Cancel this takeoff?"
        description="The AI service is told to stop, and the uploaded drawing is removed. You'll need to upload it again."
        confirmLabel="Cancel run"
        cancelLabel="Keep processing"
        confirmVariant="danger"
        onConfirm={handleCancel}
        onCancel={cancelDialog.close}
      />
    </PageTransition>
  )
}

/** Minutes/seconds since the run was submitted; freezes once it finishes. */
function useElapsed(submittedAt: string | null, running: boolean): string {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!running) return
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [running])

  if (!submittedAt) return '—'
  const seconds = Math.max(0, Math.floor((now - new Date(submittedAt).getTime()) / 1000))
  const minutes = Math.floor(seconds / 60)

  return minutes > 0 ? `${minutes}m ${String(seconds % 60).padStart(2, '0')}s` : `${seconds}s`
}

const PILL_STYLES = {
  brand: 'border-brand/40 bg-brand/15 text-brand',
  success: 'border-status-success/40 bg-status-success/12 text-status-success',
  danger: 'border-status-danger/50 bg-status-danger/20 text-red-300',
} as const

function StatusPill({
  tone,
  label,
  isRunning,
}: {
  tone: keyof typeof PILL_STYLES
  label: string
  isRunning: boolean
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-2 rounded-panel border px-5 py-2.5 text-md font-semibold',
        PILL_STYLES[tone],
      )}
    >
      {isRunning && <LoaderCircle size={17} aria-hidden className="animate-spin" />}
      {label}
    </span>
  )
}

function MetricTile({ icon: Icon, value, label }: { icon: LucideIcon; value: string; label: string }) {
  return (
    <div className="flex items-center gap-3 rounded-panel border border-hairline bg-white/4 px-3 py-3">
      <span className="grid size-11 shrink-0 place-items-center rounded-panel bg-ocean-600 text-white">
        <Icon size={20} aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="truncate text-xl leading-tight font-bold text-white">{value}</p>
        <p className="truncate text-xs text-white/80">{label}</p>
      </div>
    </div>
  )
}

Processing.layout = appLayout
