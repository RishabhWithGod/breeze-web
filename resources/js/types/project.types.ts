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
  /**
   * The drawing the next takeoff runs against — chosen on this screen, or the
   * first on record when nothing has been chosen. Null with no drawings at all.
   */
  readonly selectedUploadId: number | null
  readonly pageCount: number
  readonly startedAt: string | null
  readonly completedAt: string | null
  /** Set once the AI engine has returned a result for this project. */
  readonly takeoffUrl: string | null
}

/** Project timeline entries share the takeoff activity shape. */
export type ProjectActivityEntry = ActivityEntry

/**
 * The Create Client payload.
 *
 * No drawings: a PDF is uploaded through AI Takeoff, against a client that
 * already exists, so this is a plain JSON post rather than a multipart one.
 */
export interface ProjectDraft {
  /** The client's name. The `client` column is written from it server-side. */
  name: string
  code: string
  /** Every site. The first is the primary, and is mirrored onto `location`. */
  addresses: DraftAddress[]
  project_type: string
  due_date: string
  notes: string
}

/** One site being typed into the Create Client form, before it is saved. */
export interface DraftAddress {
  label: string
  address: string
  /** Set only when the address was picked from the lookup, never when typed. */
  latitude: number | null
  longitude: number | null
}

/** One site already on a client's record. */
export interface ClientAddressOption {
  readonly id: number
  readonly label: string | null
  readonly address: string
  /** Label and address as one line, for a list. */
  readonly display: string
  readonly isPrimary: boolean
}

/** One address the geocoder matched, with the point behind it. */
export interface AddressSuggestion {
  readonly label: string
  readonly latitude: number
  readonly longitude: number
}

/**
 * A client, offered in the single Client select on every intake form.
 *
 * Clients are projects — the same record — so picking one here both names the
 * client and scopes the AI Takeoff drawings offered below it. `location`,
 * `dueDate` and `projectType` are carried over into a blank field on the form
 * rather than retyped.
 */
export interface ClientOption {
  readonly id: number
  readonly name: string
  readonly dueDate: string | null
  readonly projectType: JobType | null
  /** Every site this client has work at. A job picks from these. */
  readonly addresses: readonly ClientAddressOption[]
}

/**
 * A drawing already run through AI Takeoff, offered once its client is
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
