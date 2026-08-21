import { useCallback, useEffect, useRef, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import { ArrowRight, Ban, CheckCircle2, Cog, RotateCcw, TriangleAlert } from 'lucide-react'
import {
  Alert,
  Button,
  Card,
  ConfirmDialog,
  ProgressBar,
  StatusChip,
} from '@/components/common'
import { PageHeader, PageTransition, StepWizard, appLayout } from '@/components/layout'
import { ProcessingStepList, ProcessingVisual } from '@/components/processing'
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

  return (
    <PageTransition>
      <Head title="AI Takeoff Processing" />

      <StepWizard current="analysis" hrefs={{ upload: ROUTES.upload }} />

      <PageHeader
        title="AI Takeoff Processing"
        subtitle={
          uploads.length > 0
            ? `Analysing ${uploads.length} file(s) from ${project.name}.`
            : 'Analysing your electrical plans.'
        }
        breadcrumbs={[{ label: 'AI Takeoff', href: ROUTES.aiTakeoff }, { label: 'Processing' }]}
        actions={
          <StatusChip
            tone={isFailed ? 'danger' : isCancelled ? 'warning' : isDone ? 'success' : 'brand'}
            label={
              isFailed
                ? 'Failed'
                : isCancelled
                  ? 'Cancelled'
                  : isDone
                    ? 'Completed'
                    : run.stageLabel ?? 'Processing'
            }
            pulse={isRunning}
          />
        }
      />

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
        <Card variant="spotlight" padding="lg" className="text-center">
          <ProcessingVisual isActive={isRunning} />

          <h2 className="mt-6 text-2xl font-bold text-white sm:text-3xl">
            {isFailed
              ? 'The analysis could not be completed'
              : isCancelled
                ? 'Processing cancelled'
                : isDone
                  ? 'Takeoff complete'
                  : 'AI Takeoff Processing'}
          </h2>

          <ProgressBar
            value={displayProgress}
            size="lg"
            showValue
            tone={isFailed || isCancelled ? 'danger' : isDone ? 'success' : 'brand'}
            className="mt-7"
          />

          <div className="mt-6 inline-flex items-center gap-3">
            {isDone ? (
              <CheckCircle2 size={22} aria-hidden className="text-status-success" />
            ) : isFailed || isCancelled ? (
              <TriangleAlert size={22} aria-hidden className="text-red-300" />
            ) : (
              <Cog size={22} aria-hidden className="animate-spin-slow text-brand" />
            )}
            <p className="text-xl font-semibold text-white">
              {isDone
                ? 'Detections are ready to review'
                : isFailed || isCancelled
                  ? 'Nothing was written to your takeoff'
                  : `${run.stageLabel ?? stageStates[Math.max(reachedIndex, 0)]?.label ?? 'Working'}…`}
            </p>
          </div>

          <p className="mx-auto mt-4 max-w-xl text-md text-white/90">
            {isDone
              ? 'Every detected symbol is waiting for review. Nothing reaches an estimate until you approve it.'
              : isFailed
                ? run.error ?? 'The AI service did not return a usable response.'
                : isCancelled
                  ? 'The run was cancelled. Resubmit the drawing when you are ready.'
                  : 'The drawing is with the AI service. Progress updates as it reports back — you may navigate away.'}
          </p>

          {(run.runId || run.processingTime) && (
            <p className="mt-2 font-mono text-2xs text-white/65">
              {run.runId ? `engine run ${run.runId}` : ''}
              {run.runId && run.processingTime ? ' · ' : ''}
              {run.processingTime ? `${run.processingTime.toFixed(1)}s` : ''}
            </p>
          )}

          <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
            {isDone ? (
              <>
                <Button variant="white" leftIcon={RotateCcw} onClick={handleStartOver}>
                  New takeoff
                </Button>
                <Button
                  rightIcon={ArrowRight}
                  onClick={() => run.reviewUrl && router.visit(run.reviewUrl)}
                >
                  Continue to review
                </Button>
              </>
            ) : isFailed || isCancelled ? (
              <>
                <Button variant="white" leftIcon={RotateCcw} onClick={handleRestart}>
                  Resubmit drawing
                </Button>
                <Button variant="dark" onClick={handleStartOver}>
                  Back to upload
                </Button>
              </>
            ) : (
              <Button variant="white" leftIcon={Ban} onClick={cancelDialog.open}>
                Cancel run
              </Button>
            )}
          </div>

          {(run.warnings?.length ?? 0) > 0 && (
            <Alert tone="warning" className="mt-8 text-left" title="Engine warnings">
              <ul className="mt-1 flex flex-col gap-1">
                {run.warnings?.map((warning) => (
                  <li key={warning} className="text-sm">
                    {warning}
                  </li>
                ))}
              </ul>
            </Alert>
          )}

          {/*
            A queued run that nothing has claimed means no queue worker is running.
            Said plainly, with the one action that fixes it, rather than spinning.
          */}
          {run.awaitingWorker && isRunning && (
            <Alert
              tone="warning"
              className="mt-8 text-left"
              title="Waiting for a queue worker"
            >
              <p>
                The drawing is stored and the analysis is queued, but no worker has
                picked it up. Start one with <code>php artisan queue:work</code> (or{' '}
                <code>composer run dev</code>), then this screen carries on by itself.
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

          {isRunning && !run.awaitingWorker && (
            <Alert tone="info" className="mt-8 text-left">
              The engine is identifying symbols, circuits and connections. Cancelling
              stops the run — the drawing stays uploaded so it can be resubmitted.
            </Alert>
          )}
        </Card>

        <Card padding="lg">
          <h3 className="mb-6 text-xl font-semibold text-white">Processing steps</h3>
          <ProcessingStepList stages={stageStates} />

          <div className="mt-6 grid grid-cols-2 gap-3 border-t border-hairline pt-5">
            <div className="rounded-panel bg-navy-950/35 p-3">
              <p className="text-xs tracking-wide text-white/70 uppercase">Reported</p>
              <p className="mt-1 text-lg font-semibold text-white tabular-nums">
                {displayProgress}%
              </p>
            </div>
            <div className="rounded-panel bg-navy-950/35 p-3">
              <p className="text-xs tracking-wide text-white/70 uppercase">Stage</p>
              <p className="mt-1 truncate text-lg font-semibold text-white">
                {run.stageLabel ?? '—'}
              </p>
            </div>
          </div>
        </Card>
      </div>

      <ConfirmDialog
        isOpen={cancelDialog.isOpen}
        tone="danger"
        title="Cancel this takeoff?"
        description="The AI service is told to stop. Your drawing stays uploaded so you can resubmit it."
        confirmLabel="Cancel run"
        cancelLabel="Keep processing"
        confirmVariant="danger"
        onConfirm={handleCancel}
        onCancel={cancelDialog.close}
      />
    </PageTransition>
  )
}

Processing.layout = appLayout
