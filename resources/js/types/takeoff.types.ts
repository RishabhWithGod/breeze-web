export type TakeoffStatus = 'completed' | 'converted' | 'draft' | 'failed' | 'processing'

export interface DetectedSymbol {
  readonly id: number
  readonly code: string
  readonly name: string
  readonly category: 'Lighting' | 'Power' | 'Data' | 'Fire Alarm' | 'Distribution'
  readonly count: number
  /** 0–1 model confidence. */
  readonly confidence: number
  readonly unit: string
}

export interface TakeoffSummaryMetric {
  readonly id: number
  readonly label: string
  readonly value: string
  readonly delta?: string | null
  readonly trend?: 'up' | 'down' | 'flat' | null
  readonly hint?: string | null
}

export interface DrawingSheet {
  readonly id: number
  readonly code: string
  readonly title: string
  readonly pageCount: number
  readonly scale: string
  readonly symbolCount: number
}

export interface ActivityEntry {
  readonly id: number
  readonly title: string
  readonly description: string
  readonly timestamp: string
  readonly tone: 'brand' | 'success' | 'warning' | 'info'
}

export interface TakeoffProject {
  readonly id: number
  readonly name: string
  readonly client: string
  readonly drawingName: string | null
  readonly discipline: string
  readonly status: TakeoffStatus
  readonly createdAt: string
  readonly completedAt: string | null
  readonly pageCount: number
  readonly overallConfidence: number | null
  readonly sheets: readonly DrawingSheet[]
  readonly symbols: readonly DetectedSymbol[]
  readonly metrics: readonly TakeoffSummaryMetric[]
  readonly activity: readonly ActivityEntry[]
}

/** A takeoff as it appears in the history table. */
export interface TakeoffHistoryRow {
  readonly id: number
  readonly name: string
  readonly client: string
  readonly date: string
  readonly status: TakeoffStatus
  readonly items: number
}
