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
  /** Assigned after intake, so absent on a freshly created job. */
  readonly foreman: Pick<JobForeman, 'name' | 'initials'> | null
  /** ISO timestamps — formatted with date-fns at render time. */
  readonly startDate: string | null
  readonly endDate: string | null
  /** Whole currency units. */
  readonly budget: number | null
  readonly isArchived: boolean
  readonly teamCount: number
  readonly estimateCount: number
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

/** One task on a job, as the detail screen lists it. */
export interface JobTaskSummary {
  readonly id: number
  readonly title: string
  readonly status: TaskStatus
  readonly foreman: string | null
  readonly estimatedHours: number | null
  readonly actualHours: number | null
  /** How much of the estimate this task covers. */
  readonly lineCount: number
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
  /** The client's own id — clients are projects, so this is a `projects` id. */
  readonly clientId: number | null
  /** The client sites this job runs at, in the order they were picked. */
  readonly addressIds: readonly number[]
  readonly archivedAt: string | null
  readonly createdAt: string
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
  name: string
  /**
   * The client sites this job is at, in the order picked. The first one's
   * address becomes the job's own `location` snapshot, server-side.
   */
  address_ids: number[]
  description: string
  job_type: JobType | ''
  start_date: string
  end_date: string
  budget: string
  save_as_draft: boolean
  /**
   * The client. Clients are projects, so this is a `projects` id — the job's
   * own `client` name column is a snapshot the server writes from it.
   */
  project_id: string
  upload_id: string
}
