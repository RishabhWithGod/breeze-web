import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { stageBounds, totalDuration } from '@/constants'
import type {
  ProcessingSnapshot,
  ProcessingStage,
  ProcessingStageState,
  ProcessingStageStatus,
} from '@/types'

const TICK_MS = 80

interface PipelineOptions {
  /** Start the timer immediately on mount. */
  autoStart?: boolean
  onComplete?: () => void
}

interface PipelineControls extends ProcessingSnapshot {
  isRunning: boolean
  isPaused: boolean
  totalMs: number
  start: () => void
  pause: () => void
  resume: () => void
  restart: () => void
  cancel: () => void
  /** Skips the timer and jumps to the finished state. */
  complete: () => void
}

function resolveStatus(
  bounds: { start: number; end: number } | undefined,
  elapsedMs: number,
  isCancelled: boolean,
): ProcessingStageStatus {
  if (!bounds) return 'pending'

  const isCurrent = elapsedMs >= bounds.start && elapsedMs < bounds.end
  if (isCancelled && isCurrent) return 'failed'
  if (elapsedMs >= bounds.end) return 'complete'
  return isCurrent ? 'active' : 'pending'
}

/**
 * Drives the progress display for a takeoff run.
 *
 * The server owns the stage list and its timings; this maps elapsed time onto
 * those stages so the screen animates while the run is open. Reaching the end
 * fires `onComplete`, which is where the run is actually marked finished.
 */
export function useProcessingPipeline(
  stages: readonly ProcessingStage[],
  options: PipelineOptions = {},
): PipelineControls {
  const { autoStart = true, onComplete } = options
  const [elapsedMs, setElapsedMs] = useState(0)
  const [isRunning, setIsRunning] = useState(autoStart)
  const [isCancelled, setIsCancelled] = useState(false)
  const hasCompletedRef = useRef(false)
  const onCompleteRef = useRef(onComplete)

  const bounds = useMemo(() => stageBounds(stages), [stages])
  const totalMs = useMemo(() => totalDuration(stages), [stages])

  useEffect(() => {
    onCompleteRef.current = onComplete
  }, [onComplete])

  useEffect(() => {
    if (!isRunning) return undefined

    const interval = window.setInterval(() => {
      setElapsedMs((previous) => {
        const next = previous + TICK_MS
        if (next >= totalMs) {
          setIsRunning(false)
          return totalMs
        }
        return next
      })
    }, TICK_MS)

    return () => window.clearInterval(interval)
  }, [isRunning, totalMs])

  const isComplete = totalMs > 0 && elapsedMs >= totalMs

  useEffect(() => {
    if (isComplete && !hasCompletedRef.current) {
      hasCompletedRef.current = true
      onCompleteRef.current?.()
    }
  }, [isComplete])

  const stageStates = useMemo<ProcessingStageState[]>(
    () =>
      stages.map((stage, index) => ({
        ...stage,
        status: resolveStatus(bounds[index], elapsedMs, isCancelled),
      })),
    [stages, bounds, elapsedMs, isCancelled],
  )

  const currentIndex = stageStates.findIndex(
    (stage) => stage.status === 'active' || stage.status === 'failed',
  )
  const activeIndex = currentIndex === -1 ? stageStates.length - 1 : currentIndex

  const progress =
    totalMs === 0 ? 0 : Math.min(100, Math.round((elapsedMs / totalMs) * 100))

  const start = useCallback(() => {
    setIsCancelled(false)
    setIsRunning(true)
  }, [])

  const pause = useCallback(() => setIsRunning(false), [])
  const resume = useCallback(() => setIsRunning(true), [])

  const restart = useCallback(() => {
    hasCompletedRef.current = false
    setIsCancelled(false)
    setElapsedMs(0)
    setIsRunning(true)
  }, [])

  const cancel = useCallback(() => {
    setIsRunning(false)
    setIsCancelled(true)
  }, [])

  const complete = useCallback(() => {
    setIsCancelled(false)
    setIsRunning(false)
    setElapsedMs(totalMs)
  }, [totalMs])

  return {
    stages: stageStates,
    activeIndex,
    progress,
    isComplete,
    elapsedMs,
    totalMs,
    isRunning,
    isPaused: !isRunning && !isComplete && !isCancelled && elapsedMs > 0,
    start,
    pause,
    resume,
    restart,
    cancel,
    complete,
  }
}
