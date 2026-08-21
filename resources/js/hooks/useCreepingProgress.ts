import { useEffect, useRef, useState } from 'react'

const TICK_MS = 300
/** Higher = slower creep — tuned so it feels alive without ever looking rushed. */
const CREEP_TAU_MS = 20000

/**
 * The AI engine only ever reports these six milestones (see
 * `TakeoffOrchestrator`) — most notably a single jump from 35 to 85 that
 * spans the entire real analysis call, which can run for minutes. Each entry
 * is how far the display is allowed to creep past that milestone while
 * waiting for the next one, so the bar keeps moving instead of sitting
 * frozen, and still snaps to the real value the instant the server reports it.
 */
const CEILINGS: Record<number, number> = {
  0: 8,
  10: 31,
  35: 79,
  85: 90,
  92: 98,
  100: 100,
}

/**
 * Smooths a coarse, milestone-only progress value into something that keeps
 * moving between real updates.
 *
 * Never invents a value ahead of what the server last confirmed by more than
 * its milestone's ceiling, and snaps straight to `real` the moment it changes
 * or the run stops — so it can visibly creep during a long wait, but never
 * claims to be further along than the engine has actually reported.
 */
export function useCreepingProgress(real: number, isRunning: boolean): number {
  const [display, setDisplay] = useState(real)
  const anchorRef = useRef<{ value: number; at: number } | null>(null)

  useEffect(() => {
    if (!isRunning) {
      anchorRef.current = null
      return undefined
    }

    const interval = window.setInterval(() => {
      const now = Date.now()

      if (anchorRef.current === null || anchorRef.current.value !== real) {
        anchorRef.current = { value: real, at: now }
        setDisplay(real)
        return
      }

      const ceiling = CEILINGS[real] ?? Math.min(real + 5, 99)
      const elapsed = now - anchorRef.current.at
      const eased = 1 - Math.exp(-elapsed / CREEP_TAU_MS)
      setDisplay(Math.round(real + (ceiling - real) * eased))
    }, TICK_MS)

    return () => window.clearInterval(interval)
  }, [real, isRunning])

  return isRunning ? display : real
}
