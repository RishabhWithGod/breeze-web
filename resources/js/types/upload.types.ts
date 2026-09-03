/** Lifecycle of a single file inside the dropzone before it is sent. */
export type UploadFileStatus = 'ready' | 'uploading' | 'success' | 'error'

export type SupportedFormat = 'PDF' | 'CAD' | 'DWG' | 'BIM'

export interface UploadFile {
  readonly id: string
  readonly name: string
  readonly size: number
  readonly extension: string
  readonly addedAt: number
  /** The browser File, held so the queue can actually be posted. */
  readonly source: File
  status: UploadFileStatus
  /** 0–100, driven by the real upload's progress events. */
  progress: number
  errorMessage?: string
}

export interface RejectedUploadFile {
  readonly name: string
  readonly reason: string
}

/** A stored upload, as listed in "Recent Activity". */
export interface RecentUpload {
  readonly id: number
  readonly name: string
  readonly format: SupportedFormat
  readonly sizeBytes: number
  /** ISO timestamp — formatted with date-fns at render time. */
  readonly uploadedAt: string
  readonly status: 'completed' | 'processing' | 'failed'
}

/** A project the upload screen can attach a takeoff run to. */
export interface UploadTargetProject {
  readonly id: number
  readonly name: string
  /** Whose project it is — two clients can both have a "Phase 2". */
  readonly clientName: string | null
}

/** Upload limits, mirrored from config/takeoff.php as page props. */
export interface UploadLimits {
  readonly maxFiles: number
  readonly maxFileSizeMb: number
  readonly extensions: readonly string[]
  /** Set when PHP's own limit is lower than the product's, explaining how to lift it. */
  readonly serverHint: string | null
}
