/**
 * Documents — files filed against jobs, estimates and folders, with version
 * history, favorites, sharing and an archive. Mirrors `DocumentResource` and
 * `DocumentController`. Nothing here is computed twice: every value is
 * exactly what the backend returned.
 */

export type DocumentTab = 'all' | 'recent' | 'shared' | 'favorites' | 'archived'

export type DocumentVersionStatus = 'all' | 'latest' | 'superseded'

export type DocumentModifiedRange = 'all' | 'today' | '7' | '30' | '90'

export interface Document {
  readonly id: number
  readonly name: string
  readonly originalFilename: string
  readonly documentType: string
  readonly extension: string | null
  readonly mimeType: string | null
  readonly fileSize: number
  readonly version: number
  readonly versionLabel: string
  readonly isLatest: boolean
  readonly isArchived: boolean
  readonly visibility: 'team' | 'private'
  readonly description: string | null
  readonly jobId: number | null
  readonly jobName: string | null
  /** The takeoff this is filed under. */
  readonly projectId: number | null
  readonly estimateId: number | null
  readonly estimateNumber: string | null
  readonly folderId: number | null
  readonly folderName: string | null
  readonly uploadedBy: number | null
  readonly uploaderName: string | null
  readonly uploadedAt: string
  readonly modifiedAt: string
  readonly isFavorite: boolean
  readonly isShared: boolean
  readonly aiTakeoffProjectId: number | null
  readonly canEdit: boolean
  readonly canDelete: boolean
  readonly canShare: boolean
  readonly downloadUrl: string
  readonly previewUrl: string
  readonly historyUrl: string
}

export interface DocumentFilters {
  /** Which list this is — one takeoff's paperwork, or the whole workspace's. */
  readonly project: number | null
}

export interface DocumentJobOption {
  readonly id: number
  readonly name: string
}

export interface DocumentFolderOption {
  readonly id: number
  readonly name: string
  readonly parent_id: number | null
  readonly job_id: number | null
}

/** An AI Takeoff drawing not yet registered as a Document — importable without a second upload. */
export interface ImportableUpload {
  readonly id: number
  readonly label: string
  readonly projectId: number | null
  readonly projectName: string | null
}

export interface DocumentAbilities {
  readonly createFolder: boolean
  readonly manage: boolean
}

export type DocumentTabCounts = Record<DocumentTab, number>
