import type { JobAssignmentRow } from './review.types'
import type { TaskStatus } from './scheduling.types'

export type JobStatus =
  | 'draft'
  | 'planning'
  | 'scheduled'
  | 'in-progress'
  | 'on-hold'
  | 'delayed'
  | 'completed'

export type JobType = 'residential' | 'commercial' | 'industrial'

export interface JobForeman {
  readonly id: number
  readonly name: string
  readonly initials: string
}

export interface Job {
  readonly id: number
  readonly name: string
  readonly client: string | null
  readonly location: string | null
  readonly description: string | null
  readonly jobType: JobType | null
  readonly status: JobStatus
  /** True once `status` is 'completed' — nothing about the job can change again. */
  readonly isLocked: boolean
  /** Assigned after intake, so absent on a freshly created job. */
  readonly foreman: Pick<JobForeman, 'name' | 'initials'> | null
  /** The crew the job is handed to. Null for a job with no team yet. */
  readonly teamName: string | null
  /** ISO timestamps — formatted with date-fns at render time. */
  readonly startDate: string | null
  readonly endDate: string | null
  /** Whole currency units. */
  readonly budget: number | null
  readonly isArchived: boolean
  readonly teamCount: number
  readonly estimateCount: number
  /** Whether an invoice has already been raised for this job — see `invoiceId`. */
  readonly hasInvoice: boolean
  /** The existing invoice's id, when `hasInvoice` — for "View Invoice" instead of raising a second one. */
  readonly invoiceId: number | null
  /** Who's currently staffed, and in what role — same shape as the detail screen. */
  readonly assignments: readonly JobAssignmentRow[]
  readonly options: {
    readonly createEstimate: boolean
    readonly assignTeam: boolean
    readonly notifyClient: boolean
  }
}

export interface JobTeamMember {
  readonly id: number
  readonly name: string
  readonly initials: string
  readonly role: string
}

export interface JobEstimateSummary {
  readonly id: number
  readonly number: string
  readonly client: string
  readonly date: string
  readonly amount: number
  readonly status: 'draft' | 'sent' | 'approved' | 'rejected'
  readonly isConverted: boolean
  readonly convertedProjectId: number | null
}

/** A note left on a task from the field — the mobile app's own Materials screen. */
export interface TaskFieldNote {
  readonly id: number
  readonly body: string
  readonly author: string
  readonly createdAt: string
}

/** A photo attached to a task from the field — same mobile screen as the note above. */
export interface TaskFieldPhoto {
  readonly id: number
  readonly name: string
  readonly mime: string | null
  readonly sizeBytes: number
  readonly uploadedBy: string
  readonly createdAt: string
  readonly url: string
}

/**
 * One material/fixture/equipment line within a task, with its own field
 * notes/photos — one level finer than the task's own `comments`/
 * `attachments` below, and what the mobile Materials screen actually
 * writes to now (labor lines are the task's checklist, not a "material",
 * so they never appear here).
 */
export interface JobTaskMaterialLine {
  readonly id: number
  readonly description: string
  readonly category: string | null
  readonly quantity: number
  readonly unit: string | null
  readonly comments: readonly TaskFieldNote[]
  readonly attachments: readonly TaskFieldPhoto[]
}

/** One task on a job, as the detail screen lists it. */
export interface JobTaskSummary {
  readonly id: number
  readonly title: string
  readonly status: TaskStatus
  readonly foreman: string | null
  /** Who is over it. Null for work with a foreman and nobody above them. */
  readonly supervisor: string | null
  readonly estimatedHours: number | null
  readonly actualHours: number | null
  /** How much of the estimate this task covers. */
  readonly lineCount: number
  /**
   * Left from the field, via the mobile app — read-only here. Legacy:
   * mobile now writes per-material instead (see `materialLines`), so this
   * stays populated only for notes/photos added before that change.
   */
  readonly comments: readonly TaskFieldNote[]
  readonly attachments: readonly TaskFieldPhoto[]
  /** This task's own materials, each with its own notes/photos. */
  readonly materialLines: readonly JobTaskMaterialLine[]
}

export interface JobNote {
  readonly id: number
  readonly body: string
  readonly author: string
  readonly createdAt: string
}

export interface JobAttachment {
  readonly id: number
  readonly name: string
  readonly size: number
  readonly mime: string | null
  readonly uploadedBy: string
  readonly createdAt: string
  readonly downloadUrl: string
}

export interface JobActivity {
  readonly id: number
  readonly type: string
  readonly description: string
  readonly meta: Record<string, unknown> | null
  readonly actor: string
  readonly createdAt: string
}

export interface JobStatusChange {
  readonly id: number
  readonly from: JobStatus | null
  readonly to: JobStatus
  readonly actor: string
  readonly createdAt: string
}

/** One line of the bill of quantities copied onto a job. */
export interface JobBoqLine {
  readonly symbol: string
  readonly count: number
  readonly unit: string
  readonly extended_cost: number
  readonly labor_hours: number
}

/** Everything a job created from a reviewed takeoff carries with it. */
export interface JobTakeoff {
  readonly aiResultId: number
  /** False while the counts are the engine's own, before the review is signed off. */
  readonly reviewed: boolean
  readonly projectId: number | null
  readonly drawingName: string | null
  readonly projectName: string | null
  readonly engineVersion: string | null
  readonly engineRunId: string | null
  /** Seconds the engine spent on the drawing. */
  readonly processingTime: number | null
  readonly pipelineStatus: readonly { stage: string; status: string }[]
  readonly warnings: readonly string[]
  readonly approvedItems: number | null
  readonly symbolTypes: number | null
  readonly laborHours: number | null
  readonly materialCost: number | null
  /** Reviewed count per symbol name, copied at job creation. */
  readonly symbolCounts: Readonly<Record<string, number>>
  readonly boqLines: readonly JobBoqLine[]
  readonly wireSizes: readonly { size: string; page: number; count: number }[]
  readonly engineEstimate: {
    readonly subtotal?: number
    readonly tax?: number
    readonly grand_total?: number
    readonly currency?: string
    readonly line_count?: number
  } | null

  /* Documents — always the current files, unlike the copied numbers above. */
  readonly pdfUrl: string | null
  readonly pdfDetailsUrl: string | null
  readonly annotatedPdfUrl: string
  readonly originalJsonUrl: string
  readonly finalJsonUrl: string
  readonly reviewUrl: string
  readonly finalSymbolsUrl: string
}

/** The full record rendered by the job detail screen. */
export interface JobDetail extends Omit<Job, 'teamCount' | 'estimateCount'> {
  /** Who the work is for, read through the project it is on. */
  readonly clientId: number | null
  /** And which of their projects that is. */
  readonly projectId: number | null
  /** The drawing the work is taken off, through the takeoff it is linked to. */
  readonly uploadId: number | null
  /** True once reviewed counts were copied onto the job: the drawing is settled. */
  readonly drawingIsFixed: boolean
  /** The client sites this job runs at, in the order they were picked. */
  readonly addressIds: readonly number[]
  /** The site's point, for GPS check-in on the crew app. */
  readonly latitude: number | null
  readonly longitude: number | null
  readonly placeId: string | null
  readonly archivedAt: string | null
  readonly createdAt: string
  /**
   * The crew the job is handed to — one team, picked when the job was raised.
   * Not to be confused with `team` below, which is the individual people
   * staffed onto it.
   */
  readonly teamId: number | null
  readonly teamName: string | null
  readonly team: readonly JobTeamMember[]
  readonly estimates: readonly JobEstimateSummary[]
  /** The work the job is broken into, in schedule order. */
  readonly tasks: readonly JobTaskSummary[]
  readonly notes: readonly JobNote[]
  readonly attachments: readonly JobAttachment[]
  readonly activities: readonly JobActivity[]
  readonly statusHistory: readonly JobStatusChange[]
  readonly assignments: readonly JobAssignmentRow[]
  readonly takeoff: JobTakeoff | null
}

/**
 * Payload posted by the Create New Job screen. Field names are snake_case
 * because they map straight onto StoreJobRequest's validation rules.
 * `status` is derived server-side from `save_as_draft`.
 */
export interface JobDraft {
  /** Who the work is for. */
  client_id: string
  name: string
  /**
   * The site this job is at, one of its project's own. Its address becomes the
   * job's `location` snapshot, server-side.
   */
  address_ids: number[]
  description: string
  job_type: JobType | ''
  /**
   * The crew this job is handed to. Required — it narrows the crew and
   * foreman pickers on the job's tasks, so a task cannot be staffed until
   * one is picked.
   */
  team_id: string
  start_date: string
  end_date: string
  /*
   * No `budget` field: a job's budget is the estimate raised against its
   * drawing, written server-side — never typed on this form.
   */
  save_as_draft: boolean
  /**
   * Which of the client's projects the work is on. Every drawing, takeoff and
   * estimate hangs off one, so the job does too. The job's own `client` name
   * column is a snapshot the server writes from `client_id`.
   */
  project_id: string
  upload_id: string
}
