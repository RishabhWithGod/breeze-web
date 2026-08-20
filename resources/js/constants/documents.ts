import type { Accept } from 'react-dropzone'
import type { DocumentTab } from '@/types'

/** Mirrors `config('documents.extensions')` — wider than Takeoff's CAD-only list. */
export const DOCUMENT_MAX_FILE_SIZE_MB = 500

export const DOCUMENT_SUPPORTED_FORMATS = ['PDF', 'DOC', 'XLS', 'IMG', 'CAD'] as const

export const DOCUMENT_DROPZONE_ACCEPT: Accept = {
  'application/pdf': ['.pdf'],
  'application/acad': ['.dwg', '.dxf'],
  'application/msword': ['.doc'],
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': ['.docx'],
  'application/vnd.ms-excel': ['.xls'],
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'],
  'text/csv': ['.csv'],
  'text/plain': ['.txt'],
  'image/png': ['.png'],
  'image/jpeg': ['.jpg', '.jpeg'],
  'image/gif': ['.gif'],
  'application/octet-stream': ['.dwg', '.dxf'],
}

/** Mirrors `Document::TYPES`. */
export const DOCUMENT_TYPE_VALUES = [
  'Blueprint',
  'Electrical Drawing',
  'Specification',
  'Estimate',
  'Invoice',
  'Contract',
  'Change Order',
  'Schedule',
  'Report',
  'Photo',
  'Other',
] as const

export const DOCUMENT_TYPE_FILTERS = [
  { label: 'All Types', value: 'all' },
  ...DOCUMENT_TYPE_VALUES.map((type) => ({ label: type, value: type })),
] as const

export const DOCUMENT_MODIFIED_FILTERS = [
  { label: 'Any Time', value: 'all' },
  { label: 'Today', value: 'today' },
  { label: 'Last 7 Days', value: '7' },
  { label: 'Last 30 Days', value: '30' },
  { label: 'Last 90 Days', value: '90' },
] as const

export const DOCUMENT_VERSION_STATUS_FILTERS = [
  { label: 'Any Version', value: 'all' },
  { label: 'Latest Only', value: 'latest' },
  { label: 'Superseded', value: 'superseded' },
] as const

export const DOCUMENT_TABS: readonly { label: string; value: DocumentTab }[] = [
  { label: 'All Documents', value: 'all' },
  { label: 'Recent', value: 'recent' },
  { label: 'Shared with Me', value: 'shared' },
  { label: 'Favorites', value: 'favorites' },
  { label: 'Archived', value: 'archived' },
]
