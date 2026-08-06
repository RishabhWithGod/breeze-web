export type ProcessingStageStatus = 'pending' | 'active' | 'complete' | 'failed'

export interface ProcessingStage {
  readonly id: string
  readonly label: string
  readonly description: string
  /** Mock duration in ms used by the fake pipeline timer. */
  readonly durationMs: number
}

export interface ProcessingStageState extends ProcessingStage {
  status: ProcessingStageStatus
}

export interface ProcessingSnapshot {
  readonly stages: readonly ProcessingStageState[]
  readonly activeIndex: number
  /** 0–100 across the whole pipeline. */
  readonly progress: number
  readonly isComplete: boolean
  readonly elapsedMs: number
}
