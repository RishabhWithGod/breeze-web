import type { ProcessingStage } from '@/types'

export interface StageBounds {
  readonly start: number
  readonly end: number
}

/**
 * [start, end) timeline offsets for each stage, so the pipeline hook can map
 * elapsed time onto stages without mutating anything at render.
 *
 * The stage list itself comes from config/takeoff.php as a page prop — the
 * server owns the pipeline it describes.
 */
export function stageBounds(stages: readonly ProcessingStage[]): StageBounds[] {
  return stages.reduce<StageBounds[]>((bounds, stage) => {
    const start = bounds.at(-1)?.end ?? 0
    return [...bounds, { start, end: start + stage.durationMs }]
  }, [])
}

export function totalDuration(stages: readonly ProcessingStage[]): number {
  return stages.reduce((total, stage) => total + stage.durationMs, 0)
}
