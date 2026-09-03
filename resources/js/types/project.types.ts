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
  notes: string
}

/** One site being typed into the Create Client form, before it is saved. */
export interface DraftAddress {
  label: string
  address: string
  /**
   * What kind of building it is. Belongs to the site rather than the client —
   * one client can own a house and a warehouse — and a job raised here starts
   * from it. Blank is allowed: a site can be recorded before anyone has been.
   */
  site_type: JobType | ''
  /** Set only when the address was chosen from Google, never when typed. */
  latitude: number | null
  longitude: number | null
  place_id: string | null
}

/** One site already on a client's record. */
export interface ClientAddressOption {
  readonly id: number
  readonly label: string | null
  readonly address: string
  /** Label and address as one line, for a list. */
  readonly display: string
  /** What a job raised at this site defaults its own type to. */
  readonly siteType: JobType | null
  readonly isPrimary: boolean
  /**
   * Set only when the address was chosen from Google, and never one without
   * the other. Carried so correcting a site can keep the place it already had
   * rather than dropping it.
   */
  readonly latitude: number | null
  readonly longitude: number | null
  readonly placeId: string | null
}

/**
 * One address Google matched while typing.
 *
 * No coordinates: Autocomplete returns none, and asking for them per
 * suggestion would bill a Place Details call per keystroke. The point arrives
 * once, when a person picks one — see `PlaceSelection`.
 */
export interface PlaceSuggestion {
  readonly placeId: string
  /** The whole address on one line. */
  readonly label: string
  readonly primary: string
  readonly secondary: string
}

/**
 * An address as the form holds it: what was typed or chosen, and the place
 * behind it when there is one.
 *
 * The three location values travel together, so a stored point always belongs
 * to the address stored beside it.
 */
export interface PlaceSelection {
  readonly address: string
  readonly latitude: number | null
  readonly longitude: number | null
  readonly placeId: string | null
}

/**
 * A client, offered in the Client select on every intake form.
 *
 * Who the work is for, and where they have work. What the work *is* is one of
 * their projects, which is picked next — see `ProjectOption`.
 */
export interface ClientOption {
  readonly id: number
  readonly name: string
  readonly addresses: readonly ClientAddressOption[]
}

/**
 * A project a piece of work can be raised against.
 *
 * Every drawing, takeoff and estimate hangs off one — the client alone cannot
 * say which set of drawings a job is on.
 */
export interface ProjectOption {
  readonly id: number
  readonly clientId: number | null
  /** Whose it is — a project's name only means something beside its client. */
  readonly clientName: string | null
  readonly name: string
  readonly projectType: JobType | null
  /**
   * The drawing this project's work is taken off — chosen on its screen, or
   * the first on record. Null when it has no drawings yet.
   */
  readonly defaultUploadId: number | null
  /** The sites this project stands on. A job is at one of these. */
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
  /** Whose drawing it is, for lists that narrow by client rather than project. */
  readonly clientId: number | null
  readonly estimate: {
    readonly id: number
    readonly number: string
    readonly amount: number
    readonly status: EstimateStatus
    readonly editUrl: string
  } | null
}
