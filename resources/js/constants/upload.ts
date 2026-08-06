import type { Accept } from 'react-dropzone'
import type { SupportedFormat } from '@/types'

/**
 * Client-side mirrors of `takeoff.uploads` in config/takeoff.php.
 *
 * The server is authoritative and revalidates everything; these exist so the
 * dropzone can reject an obviously bad file without a round trip. The Upload
 * page also receives the live limits as props — prefer those where available.
 */
export const MAX_FILE_SIZE_MB = 100
export const MAX_FILE_SIZE_BYTES = MAX_FILE_SIZE_MB * 1024 * 1024
export const MAX_FILES = 8

export const SUPPORTED_FORMATS: readonly SupportedFormat[] = ['PDF', 'CAD', 'DWG', 'BIM']

/** MIME/extension map handed to react-dropzone. */
export const DROPZONE_ACCEPT: Accept = {
  'application/pdf': ['.pdf'],
  'application/acad': ['.dwg', '.dxf'],
  'image/vnd.dwg': ['.dwg'],
  'model/ifc': ['.ifc', '.bim', '.rvt'],
  'application/octet-stream': ['.dwg', '.dxf', '.bim', '.ifc', '.rvt'],
}

export const SUPPORTED_EXTENSIONS = [
  '.pdf',
  '.dwg',
  '.dxf',
  '.bim',
  '.ifc',
  '.rvt',
] as const

export const AI_FEATURES = [
  'Automatic detection of electrical components',
  'Material quantity calculations',
  'Labor hour estimates',
  'Cost breakdown analysis',
] as const

export const PROCESSING_TIME_HINT =
  'Processing time: ~2–5 minutes depending on file size'

export const MAX_NOTES_LENGTH = 500
