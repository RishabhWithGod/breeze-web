import { useEffect, useState } from 'react'
import { router, usePage } from '@inertiajs/react'
import { Pause, Play, Square, X } from 'lucide-react'
import { routeTo } from '@/constants'
import type { SharedPageProps } from '@/types'
import { cn } from '@/utils'

function elapsedNow(startedAt: string, accumulatedSeconds: number, isRunning: boolean, tick: number): number {
  if (!isRunning) return accumulatedSeconds

  const started = new Date(startedAt).getTime()
  const liveSeconds = Math.max(0, Math.floor((tick - started) / 1000))

  return accumulatedSeconds + liveSeconds
}

function formatElapsed(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60

  return [hours, minutes, seconds].map((n) => String(n).padStart(2, '0')).join(':')
}

/**
 * The one running timer, visible on every page via the shared `activeTimer`
 * prop — never a second, page-local timer state. The seconds shown tick
 * client-side for smoothness, but every actual second comes from
 * `startedAt`/`accumulatedSeconds`, the same values a refresh re-derives
 * from, so this never drifts from what the backend would compute.
 */
export function TimerIndicator() {
  const { activeTimer } = usePage<SharedPageProps>().props
  const [tick, setTick] = useState(() => Date.now())
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!activeTimer || activeTimer.status !== 'running') return undefined

    const interval = window.setInterval(() => setTick(Date.now()), 1000)
    return () => window.clearInterval(interval)
  }, [activeTimer])

  if (!activeTimer) return null

  const isRunning = activeTimer.status === 'running'
  const seconds = elapsedNow(activeTimer.startedAt, activeTimer.accumulatedSeconds, isRunning, tick)

  const act = (url: string) => {
    setBusy(true)
    router.post(url, {}, { preserveScroll: true, onFinish: () => setBusy(false) })
  }

  return (
    <div
      className={cn(
        'flex items-center gap-2 rounded-pill border px-3 py-1.5 text-sm',
        isRunning ? 'border-status-success/40 bg-status-success/10' : 'border-status-warning/40 bg-status-warning/10',
      )}
      role="status"
      aria-label={`Timer ${isRunning ? 'running' : 'paused'} on ${activeTimer.jobName}`}
    >
      <span className={cn('size-2 rounded-full', isRunning ? 'animate-pulse bg-status-success' : 'bg-status-warning')} aria-hidden />
      <span className="hidden max-w-40 truncate font-medium text-white sm:inline">{activeTimer.jobName}</span>
      <span className="font-mono tabular-nums text-white">{formatElapsed(seconds)}</span>

      <div className="flex items-center gap-1">
        {isRunning ? (
          <button type="button" aria-label="Pause timer" disabled={busy} onClick={() => act(routeTo.timerPause)} className="rounded-full p-1 text-white/80 hover:bg-white/10 hover:text-white disabled:opacity-40">
            <Pause size={14} aria-hidden />
          </button>
        ) : (
          <button type="button" aria-label="Resume timer" disabled={busy} onClick={() => act(routeTo.timerResume)} className="rounded-full p-1 text-white/80 hover:bg-white/10 hover:text-white disabled:opacity-40">
            <Play size={14} aria-hidden />
          </button>
        )}
        <button type="button" aria-label="Stop timer and log time" disabled={busy} onClick={() => act(routeTo.timerStop)} className="rounded-full p-1 text-white/80 hover:bg-white/10 hover:text-white disabled:opacity-40">
          <Square size={14} aria-hidden />
        </button>
        <button type="button" aria-label="Discard timer" disabled={busy} onClick={() => act(routeTo.timerDiscard)} className="rounded-full p-1 text-white/60 hover:bg-status-danger/15 hover:text-status-danger disabled:opacity-40">
          <X size={14} aria-hidden />
        </button>
      </div>
    </div>
  )
}
