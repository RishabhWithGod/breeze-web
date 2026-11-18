import { useCallback, useEffect, useRef, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import type { LucideIcon } from 'lucide-react'
import { ArrowRight, Ban, Clock, FileText, Layers, LoaderCircle, RotateCcw, Tag } from 'lucide-react'
import { Alert, Badge, Button, ConfirmDialog } from '@/components/common'
import { PageHeader, PageTransition, StepWizard, appLayout } from '@/components/layout'
import { ProcessingStepList } from '@/components/processing'
import { ROUTES, routeTo } from '@/constants'
import {
  useCreepingProgress,
  useDisclosure,
  useEchoConnectionState,
  usePrivateChannel,
} from '@/hooks'
import { useUploadStore } from '@/store'
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

    // Shown as cancelled straight away instead of after the round trip; put
    // back if the server refuses.
    const before = run
    setRun({ ...run, status: 'cancelled', stage: 'cancelled', stageLabel: 'Cancelled', finished: true })

    router.post(
      routeTo.processingCancel(project.id),
      {},
      { preserveScroll: true, onError: () => setRun(before) },
    )
  }, [cancelDialog, project.id, run])

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

  const sheetShown = useSheetAvailable(project.id, (uploads[0]?.format ?? project.format ?? 'pdf').toString().toLowerCase() === 'pdf')

  const eyebrow = isDone ? 'Analysis complete' : isFailed ? 'Analysis failed' : isCancelled ? 'Analysis cancelled' : 'Live analysis'
  const ringColor = tone === 'danger' ? '#f87171' : tone === 'success' ? '#22c55e' : 'url(#processing-ring)'
  const RING = 132
  const STROKE = 9
  const radius = (RING - STROKE) / 2
  const circumference = 2 * Math.PI * radius
  const ringLength = (Math.min(Math.max(displayProgress, 0), 100) / 100) * circumference

  return (
    <PageTransition>
      <Head title="AI Takeoff Processing" />

      <StepWizard current="analysis" hrefs={{ upload: ROUTES.upload }} />

      <PageHeader
        title="Breeze Takeoff Processing"
        subtitle={
          uploads.length > 1
            ? `Our AI is analyzing ${uploads.length} drawings from ${project.name}`
            : 'Our AI is analyzing your drawings and extracting takeoff data'
        }
        actions={
          <>
            {isRunning && (
              <Button variant="secondary" leftIcon={Ban} onClick={cancelDialog.open}>
                Cancel run
              </Button>
            )}
            {isDone && (
              <>
                <Button variant="secondary" leftIcon={RotateCcw} onClick={handleStartOver}>
                  New takeoff
                </Button>
                <Button rightIcon={ArrowRight} onClick={() => run.reviewUrl && router.visit(run.reviewUrl)}>
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
                <Button variant="secondary" onClick={handleStartOver}>
                  Back to upload
                </Button>
              </>
            )}
          </>
        }
      />

      {/*
        A queued run that nothing has claimed means no queue worker is running.
        Said plainly, with the one action that fixes it, rather than spinning.
      */}
      {run.awaitingWorker && isRunning && (
        <Alert tone="warning" className="mb-4 text-left" title="This is taking longer than usual">
          <p>
            Your drawing is saved and queued for analysis, but it hasn't started yet.
            This screen will update automatically once it does — try again if it
            doesn't move shortly.
          </p>
          <Button variant="secondary" size="sm" className="mt-3" leftIcon={RotateCcw} onClick={handleRetry}>
            Queue it again
          </Button>
        </Alert>
      )}

      <section className="blueprint grid gap-0 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <span className="bp-corner tl" aria-hidden />
        <span className="bp-corner br" aria-hidden />

        {/* No page image exists until the run returns — a drawing-sheet stand-in. */}
        <div className="p-5 sm:p-6">
          <div className="blueprint-grid relative grid min-h-72 place-items-center overflow-hidden rounded-xl border border-[rgb(126_170_255/0.22)] bg-[#0a1a3d]/70 lg:min-h-[26rem]">
            {sheetShown ? (
              /* The drawing's first page, from our own storage, in the browser's own PDF viewer. Not
                 interactive here — the page is for looking at while the AI reads it. The viewer's
                 scrollbar is pushed out of frame and the next page's edge is faded away. */
              <div className="absolute inset-0 overflow-hidden bg-[#323639]">
                <iframe
                  title={`First page of ${fileName}`}
                  src={`${routeTo.drawingFile(project.id)}#page=1&toolbar=0&navpanes=0&scrollbar=0&view=Fit`}
                  tabIndex={-1}
                  className="pointer-events-none absolute top-0 left-0 h-full border-0"
                  style={{ width: 'calc(100% + 20px)' }}
                />
                <span
                  className="pointer-events-none absolute inset-x-0 bottom-0 h-8 bg-linear-to-t from-[#323639] to-transparent"
                  aria-hidden
                />
              </div>
            ) : (
              <div className="grid aspect-[3/4] h-52 place-items-center rounded-sm bg-white/90 shadow-[0_10px_40px_-10px_rgb(0_0_0/0.6)]">
                <FileText size={52} aria-hidden className="text-[#2f5bff]/70" />
              </div>
            )}
            {isRunning && (
              <span
                className="pointer-events-none absolute inset-x-0 top-0 h-10 animate-scan border-b border-[#3ee6ff] bg-linear-to-b from-transparent to-[rgb(62_230_255/0.28)] shadow-[0_6px_14px_-4px_rgb(62_230_255/0.7)]"
                aria-hidden
              />
            )}
            <span className="absolute right-2.5 bottom-2.5 rounded-md bg-[rgb(4_10_26/0.82)] px-2 py-0.5 text-2xs font-semibold tracking-wider text-[#e6eeff]">
              {(uploads[0]?.format ?? project.format ?? 'PDF').toString().toUpperCase()}
            </span>
          </div>
        </div>

        <div className="min-w-0 border-t border-[rgb(126_170_255/0.14)] p-5 sm:p-6 lg:border-t-0 lg:border-l">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <span className="bp-eyebrow">{eyebrow}</span>
            <Badge tone={tone === 'danger' ? 'danger' : tone === 'success' ? 'success' : 'brand'}>
              {isRunning && <LoaderCircle size={13} aria-hidden className="animate-spin" />}
              {isFailed ? 'Failed' : isCancelled ? 'Cancelled' : isDone ? 'Completed' : 'Processing'}
            </Badge>
          </div>

          <div className="mt-4 flex flex-wrap items-center gap-5">
            <div
              className="relative shrink-0"
              style={{ width: RING, height: RING }}
              role="progressbar"
              aria-valuenow={Math.round(displayProgress)}
              aria-valuemin={0}
              aria-valuemax={100}
            >
              <svg width={RING} height={RING} viewBox={`0 0 ${RING} ${RING}`} aria-hidden>
                <defs>
                  <linearGradient id="processing-ring" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stopColor="#5b82ff" />
                    <stop offset="1" stopColor="#3ee6ff" />
                  </linearGradient>
                </defs>
                <circle cx={RING / 2} cy={RING / 2} r={radius} fill="none" stroke="rgba(126,170,255,.16)" strokeWidth={STROKE} />
                <circle
                  cx={RING / 2}
                  cy={RING / 2}
                  r={radius}
                  fill="none"
                  stroke={ringColor}
                  strokeWidth={STROKE}
                  strokeLinecap="round"
                  strokeDasharray={`${ringLength} ${circumference}`}
                  transform={`rotate(-90 ${RING / 2} ${RING / 2})`}
                  className="transition-[stroke-dasharray] duration-700"
                  style={{ filter: 'drop-shadow(0 0 6px rgba(62,230,255,.55))' }}
                />
              </svg>
              <div className="absolute inset-0 grid place-content-center text-center">
                <span className="font-display text-[1.75rem] leading-none font-[650] tracking-tight text-white">
                  {Math.round(displayProgress)}%
                </span>
                <span className="mt-1 text-[10.5px] font-semibold tracking-[0.08em] text-[#a7b7d9] uppercase">
                  complete
                </span>
              </div>
            </div>

            <div className="min-w-0 flex-1">
              <p className="text-[13px] text-[#a7b7d9]">{isRunning ? 'Now reading' : 'Status'}</p>
              <p className="font-display text-xl leading-snug font-[620] text-white">{headline}</p>
              <p className="mt-1 truncate text-[13px] text-[#a7b7d9]">
                {project.name} · {fileName}
              </p>
            </div>
          </div>

          <div className="mt-5 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
            <MetricTile icon={FileText} value={String(project.pageCount)} label="Pages" />
            <MetricTile icon={Tag} value={String(uploads.length || 1)} label={uploads.length === 1 ? 'File' : 'Files'} />
            <MetricTile icon={Layers} value={`${stageNumber}/${stages.length}`} label="Stage" />
            <MetricTile icon={Clock} value={elapsed} label="Elapsed" />
          </div>

          <div className="mt-5">
            <ProcessingStepList stages={stageStates} />

            {run.processingTime && (
              <p className="mt-3 font-mono text-2xs text-[#a7b7d9]">{run.processingTime.toFixed(1)}s</p>
            )}

            {(isFailed || isCancelled || isDone) && (
              <p className="mt-4 text-md text-[#d6e0f2]">
                {isDone
                  ? 'Every detected symbol is waiting for review. Nothing reaches an estimate until you approve it.'
                  : isFailed
                    ? (run.error ?? 'The AI service did not return a usable response.')
                    : 'The run was cancelled and the uploaded drawing was removed. Upload it again when you are ready.'}
              </p>
            )}
          </div>
        </div>
      </section>

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

/**
 * Whether the drawing's PDF can be shown. Asked of the server first (a HEAD request, nothing
 * downloaded), because the file is gone once a run is cancelled and a missing file would otherwise
 * show the browser's error page inside the frame.
 */
function useSheetAvailable(projectId: number, isPdf: boolean): boolean {
  const [available, setAvailable] = useState(false)

  useEffect(() => {
    if (!isPdf) return

    let active = true

    void fetch(routeTo.drawingFile(projectId), { method: 'HEAD', credentials: 'same-origin' })
      .then((response) => {
        if (active) setAvailable(response.ok && (response.headers.get('content-type') ?? '').includes('pdf'))
      })
      .catch(() => {
        if (active) setAvailable(false)
      })

    return () => {
      active = false
    }
  }, [projectId, isPdf])

  return isPdf && available
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

function MetricTile({ icon: Icon, value, label }: { icon: LucideIcon; value: string; label: string }) {
  return (
    <div className="flex items-center gap-2.5 rounded-xl border border-[rgb(126_170_255/0.14)] bg-white/[0.06] px-3 py-2.5">
      <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-[rgb(62_230_255/0.14)] text-[#3ee6ff]">
        <Icon size={16} aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="truncate font-display text-[1.0625rem] leading-tight font-[620] text-white">{value}</p>
        <p className="truncate text-xs text-[#a7b7d9]">{label}</p>
      </div>
    </div>
  )
}

Processing.layout = appLayout
