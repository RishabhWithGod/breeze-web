/** Types for the AI Review → Final JSON → Job → Estimate workflow. */

export type ReviewStatus = 'pending' | 'approved' | 'rejected'

/** Filters offered above the symbol grid. */
export type ReviewFilter =
  | 'all'
  | ReviewStatus
  | 'modified'
  | 'known'
  | 'unknown'
  | 'needs-review'
  | 'ai-rejected'

/** Which part of the engine's response produced a card. */
export type ReviewOrigin = 'symbol' | 'needs_review'

/** The engine's own verdict, before a person looks at it. */
export type AiCategory = 'known' | 'unknown' | 'rejected' | 'needs-review'

/** One entry of the engine's per-crop stage trail. */
export interface DetectionStage {
  readonly name: string
  /** The engine's own colour word, e.g. `green`. */
  readonly status: string
}

export type ReviewSort = 'position' | 'confidence-desc' | 'confidence-asc' | 'name-asc'

/** Which detectors contributed to a detection. */
export interface DetectionSources {
  readonly template: boolean
  readonly vector: boolean
  readonly vision: boolean
  readonly ocr: boolean
}

/** Stages the crop passed through inside the AI pipeline. */
export interface DetectionPipeline {
  readonly generated?: boolean
  readonly validated?: boolean
  readonly classified?: boolean
  readonly fusion?: boolean
  readonly final_json?: boolean
}

/** One detection, with the reviewer's verdict on it. */
export interface SymbolReviewRow {
  readonly id: number
  readonly externalId: string | null
  /** `crop_0001`-style id from the engine's lifecycle, when resolved. */
  readonly cropId: string | null
  readonly origin: ReviewOrigin
  readonly aiCategory: AiCategory | null
  /** Why the engine flagged it: `discovery`, `disagreement`, … */
  readonly reason: string | null
  readonly name: string
  readonly aiName: string
  /** Null for a symbol whose real locations live in `occurrences` instead — a genuinely multi-page symbol has no single honest page to report here. */
  readonly page: number | null
  /** 0–1. */
  readonly confidence: number
  /** `[x, y, width, height]` in the AI's page pixel space. */
  readonly bbox: readonly number[] | null
  readonly sources: DetectionSources
  readonly sourceLabels: readonly string[]
  /** Corroborating signals the engine listed: legend, template, vector, vision. */
  readonly evidence: readonly string[]
  readonly detectionSource: string | null
  /** The engine's verdict on the crop, e.g. "Known Symbol". */
  readonly finalDecision: string | null
  /** How many crops backed this symbol. */
  readonly cropCount: number
  readonly stages: readonly DetectionStage[]
  readonly pipeline: DetectionPipeline
  readonly legend: Record<string, unknown> | null
  readonly isKnown: boolean
  readonly aiCount: number
  readonly finalCount: number
  readonly status: ReviewStatus
  readonly notes: string | null
  readonly isModified: boolean
  readonly isRenamed: boolean
  readonly mergedIntoId: number | null
  readonly splitFromId: number | null
  readonly cropUrl: string | null
  readonly reviewedAt: string | null
}

/** One physical occurrence of a symbol on the drawing. */
/** Where a physical occurrence came from — distinct from a card's own `origin`. */
export type OccurrenceOrigin = 'ai' | 'manual' | 'duplicate'

export interface OverlayOccurrence {
  readonly key: string
  /** `[x, y, width, height]` in the AI's page pixel space — the current, reviewed position. */
  readonly bbox: readonly [number, number, number, number]
  readonly page: number
  readonly status: ReviewStatus
  readonly confidence: number
  /** Absent on data ingested before this field existed — treat as `'ai'`. */
  readonly origin?: OccurrenceOrigin
  /** Set once an occurrence is moved: the position the AI (or manual add) originally reported. */
  readonly originalBbox?: readonly [number, number, number, number]
  /** Present on a `duplicate`-origin occurrence: the key it was copied from. */
  readonly duplicatedFrom?: string
}

/**
 * The drawing overlay's own slice of a `SymbolReviewRow` — every row visible
 * to the reviewer, unpaginated, carrying its full occurrence list so the
 * overlay can draw every box on the active page.
 */
export interface OverlaySymbol {
  readonly id: number
  readonly name: string
  /** Null for a symbol whose real locations live entirely in `occurrences` — a genuinely multi-page symbol has no single honest page to report here. */
  readonly page: number | null
  /** `[x, y, width, height]` — the row's single best-match box, when it has one. */
  readonly bbox: readonly number[] | null
  readonly status: ReviewStatus
  readonly finalCount: number
  readonly aiCount: number
  readonly origin: ReviewOrigin | 'manual'
  /** One element per physical detection; `null` for a row with just one box. */
  readonly occurrences: readonly OverlayOccurrence[] | null
}

/** Dimensions of a drawing page in the AI's own pixel space. */
export interface PageDimensions {
  readonly width: number
  readonly height: number
}

/** Counters above the grid. */
export interface ReviewTally {
  readonly total: number
  readonly pending: number
  readonly approved: number
  readonly rejected: number
  readonly modified: number
  readonly known: number
  readonly unknown: number
  /** Item quantity currently destined for the final JSON. */
  readonly approvedCount: number
}

/** A stage of the engine's pipeline and how it fared. */
export interface PipelineStage {
  readonly stage: string
  /** `parsed`, `ok`, `partial`, `fallback`, … */
  readonly status: string
}

export interface AiReviewSummary {
  readonly id: number
  readonly projectId: number
  /** The client's name — `Project.client` holds the same string. */
  readonly projectName: string
  readonly drawingName: string | null
  readonly modelVersion: string | null
  readonly pageCount: number
  readonly detectionCount: number
  readonly overallConfidence: number | null
  readonly reviewStatus: 'pending' | 'in-review' | 'finalised'
  readonly isFinalised: boolean
  readonly receivedAt: string | null
  readonly finalisedAt: string | null
  readonly originalJsonUrl: string
  readonly workJobId: number | null
  readonly estimateId: number | null
  /** The engine's identifier for the run, once resolved. */
  readonly runId: string | null
  readonly engineProjectName: string | null
  /** Seconds the engine spent on the drawing. */
  readonly processingTime: number | null
  readonly pipelineStatus: readonly PipelineStage[]
  readonly warnings: readonly string[]
  readonly lifecycleStatistics: Readonly<Record<string, number>> | null
}

/** A line of the engine's own priced bill of quantities. */
export interface EngineBoqLine {
  readonly item: string
  readonly description: string
  readonly quantity: number
  readonly unit: string
  readonly unitPrice: number
  readonly subtotal: number
  /** Set when the line was matched to a reviewed symbol. */
  readonly matchedSymbol: string | null
}

/** A conductor size the engine read off the drawing. */
export interface WireSizeRow {
  readonly page: number
  readonly size: string
  readonly context: string
  readonly count: number
}

export interface PanelScheduleRow {
  readonly page: number
  readonly panelName: string
  readonly rows: readonly Record<string, unknown>[]
  readonly rawHeaders: readonly string[]
}

export interface EquipmentRow {
  readonly page: number
  readonly tag: string
  readonly description: string
  readonly rating: string
  readonly quantity: number
}

export interface CircuitRow {
  readonly page: number
  readonly number: string
  readonly description: string
  readonly breaker: string
  readonly panel: string
}

/** A row of the final symbol table. */
export interface FinalSymbolRow {
  readonly id: number
  readonly name: string
  readonly count: number
  readonly confidence: number
  readonly template: boolean
  readonly vector: boolean
  readonly vision: boolean
  readonly ocr: boolean
  readonly sources: readonly string[]
  readonly pages: readonly number[]
  readonly wasModified: boolean
  readonly wasRenamed: boolean
}

/** One line of the bill of quantities. */
export interface BoqLine {
  readonly symbol: string
  readonly count: number
  readonly unit: string
  readonly category: string
  readonly unit_cost: number
  readonly extended_cost: number
  readonly labor_hours: number
  readonly rate_matched: boolean
}

export interface BoqMaterial {
  readonly description: string
  readonly unit: string
  readonly quantity: number
  readonly unit_cost: number
  readonly extended_cost: number
}

/** An audit entry from `approval_histories`. */
export interface ApprovalHistoryEntry {
  readonly id: number
  readonly action: string
  readonly subject: string | null
  readonly description: string
  readonly from: string | null
  readonly to: string | null
  readonly actor: string | null
  readonly timestamp: string
}

export type EstimateCategory = 'material' | 'fixture' | 'labor' | 'equipment'

export interface EstimateItemRow {
  readonly id: number
  readonly category: EstimateCategory
  readonly description: string
  readonly unit: string
  readonly quantity: number
  readonly unitCost: number
  readonly total: number
  /** `ai` lines came from the takeoff, `manual` were added by hand. */
  readonly source: 'ai' | 'manual'
}

export interface EstimateTotals {
  readonly material: number
  readonly labor: number
  readonly equipment: number
  readonly subtotal: number
  readonly markupPct: number
  readonly markup: number
  readonly taxPct: number
  readonly tax: number
  readonly grandTotal: number
  readonly laborHours: number
  /** What the engine priced before review, for comparison. */
  readonly engineSubtotal: number
  readonly engineGrandTotal: number
  readonly engineLineCount: number
  readonly currency: string
}

export type AssignmentRole =
  | 'estimator'
  | 'project-manager'
  | 'foreman'
  | 'electrician'
  | 'reviewer'

export interface JobAssignmentRow {
  readonly id: number
  readonly role: AssignmentRole
  readonly roleLabel: string
  readonly name: string
  readonly notes: string | null
  readonly teamMemberId: number | null
  readonly assignedBy: string | null
  readonly assignedAt: string
  readonly releasedAt: string | null
  readonly active: boolean
}

/** Live state of an AI run, polled by the processing screen. */
export interface RunState {
  readonly id?: number
  readonly status:
    | 'queued'
    | 'uploading'
    | 'processing'
    | 'succeeded'
    | 'failed'
    | 'cancelled'
    | 'missing'
  readonly progress: number
  readonly stage: string | null
  readonly stageLabel: string | null
  readonly error: string | null
  readonly submittedAt?: string | null
  readonly completedAt?: string | null
  readonly finished: boolean
  /** Set once the response has been ingested. */
  readonly reviewUrl: string | null
  /** The engine's run id, once resolved. */
  readonly runId?: string | null
  readonly processingTime?: number | null
  readonly pipelineStatus?: readonly PipelineStage[]
  readonly warnings?: readonly string[]
  /** Queued, but nothing has claimed it — no worker is running. */
  readonly awaitingWorker?: boolean
  /**
   * Opaque stamp of the run's current state. Sent back on the next poll so the
   * server can hold the request until this changes.
   */
  readonly signature?: string
}

/** One row of an estimating component's figures, when the run returned any. */
export interface EstimatingComponentItem {
  readonly label: string | null
  readonly detail: string | null
  readonly value: string | null
  readonly page: number | null
}

/**
 * One thing the estimate needs from a takeoff.
 *
 * `pending` means the pipeline does not produce it yet — it is listed so the
 * reviewer knows what the estimate will not carry, never with an invented
 * figure standing in for the real one.
 */
export interface EstimatingComponent {
  readonly key: string
  readonly label: string
  readonly status: 'available' | 'pending'
  readonly summary: string
  readonly items: readonly EstimatingComponentItem[]
  /** Rows beyond the handful shown here. */
  readonly moreCount: number
}
