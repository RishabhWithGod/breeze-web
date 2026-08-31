import type { EstimateStatus } from './estimate.types'
import type { JobType } from './job.types'
import type { ActivityEntry, TakeoffStatus } from './takeoff.types'
import type { SupportedFormat } from './upload.types'

/** A project is typed the same way as the job raised from it. */
export type ProjectType = JobType

/** One drawing PDF defined against a project. */
export interface ProjectDocument {
  readonly id: number
  /** The file's own name. */
  readonly name: string
  /** The label given to it on the create screen, when one was given. */
  readonly title: string | null
  /** What to show: the title, falling back to the file name. */
  readonly label: string
  readonly format: SupportedFormat
  readonly sizeBytes: number
  /** 0 until the drawing has been rendered by a takeoff run. */
  readonly pageCount: number
  readonly uploadedAt: string
  /** False when the file is no longer on the disk. */
  readonly available: boolean
  /** Streams the PDF inline; null when it is unavailable. */
  readonly url: string | null
}

/** A project as the Projects list shows it. */
export interface ProjectListRow {
  readonly id: number
  readonly name: string
  /** The client's own project number, when they use one. */
  readonly code: string | null
  readonly client: string
  readonly location: string | null
  readonly discipline: string
  readonly projectType: ProjectType | null
  readonly status: TakeoffStatus
  readonly dueDate: string | null
  readonly documentsCount: number
  readonly itemsCount: number
  readonly createdAt: string
}

/** The project detail screen's subject: the list row plus everything else. */
export interface ProjectRecord extends ProjectListRow {
  readonly notes: string | null
  readonly drawingName: string | null
  readonly pageCount: number
  readonly startedAt: string | null
  readonly completedAt: string | null
  /** Set once the AI engine has returned a result for this project. */
  readonly takeoffUrl: string | null
}

/** Project timeline entries share the takeoff activity shape. */
export type ProjectActivityEntry = ActivityEntry

/**
 * The Create Project payload.
 *
 * `documents` and `document_titles` are parallel arrays — index `i` of one is the
 * label for index `i` of the other — because that is what a multipart form can
 * express.
 */
export interface ProjectDraft {
  name: string
  code: string
  client: string
  location: string
  discipline: string
  project_type: string
  due_date: string
  notes: string
  documents: File[]
  document_titles: string[]
}

/** Limits the server enforces on the PDFs, mirrored to the picker as props. */
export interface ProjectDocumentLimits {
  readonly maxFiles: number
  readonly maxFileSizeMb: number
  /** Set when PHP's own limit is the binding one, explaining how to lift it. */
  readonly serverHint: string | null
}

/** An existing project, offered on the Create Job / Create Estimate screens. */
export interface TakeoffProjectOption {
  readonly id: number
  readonly name: string
  readonly client: string | null
  readonly location: string | null
  readonly dueDate: string | null
  readonly projectType: JobType | null
}

/**
 * A drawing already run through AI Takeoff, offered once its project is
 * picked. Carries the estimate already raised against it, if there is one —
 * picking it is how a manual Job/Estimate create form links back to the
 * takeoff pipeline instead of starting a duplicate record.
 */
export interface TakeoffUploadOption {
  readonly id: number
  readonly name: string
  readonly projectId: number
  readonly estimate: {
    readonly id: number
    readonly number: string
    readonly amount: number
    readonly status: EstimateStatus
    readonly editUrl: string
  } | null
}
