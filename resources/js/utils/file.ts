import {
  MAX_FILE_SIZE_BYTES,
  MAX_FILE_SIZE_MB,
  SUPPORTED_EXTENSIONS,
} from '@/constants/upload'
import type { UploadFile } from '@/types'
import { formatFileSize, getFileExtension } from './format'

/** Crypto-backed id with a safe fallback for non-secure contexts. */
export function createId(prefix = 'id'): string {
  const random =
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? crypto.randomUUID()
      : Math.random().toString(36).slice(2, 11)
  return `${prefix}_${random}`
}

export function isSupportedFile(fileName: string): boolean {
  const extension = `.${getFileExtension(fileName).toLowerCase()}`
  return (SUPPORTED_EXTENSIONS as readonly string[]).includes(extension)
}

/** Returns a human-readable reason when a file cannot be accepted. */
export function validateFile(file: File): string | null {
  if (!isSupportedFile(file.name)) {
    return `Unsupported format — accepted types are ${SUPPORTED_EXTENSIONS.join(', ')}`
  }
  if (file.size > MAX_FILE_SIZE_BYTES) {
    return `File is ${formatFileSize(file.size)} — the limit is ${MAX_FILE_SIZE_MB} MB`
  }
  if (file.size === 0) {
    return 'File appears to be empty'
  }
  return null
}

/**
 * Wraps a browser File in the queue entry the store tracks.
 *
 * The File itself is carried along as `source` so the queue can be posted; the
 * copied fields exist so the list can render without touching the blob.
 */
export function toUploadFile(file: File): UploadFile {
  const error = validateFile(file)
  return {
    id: createId('file'),
    name: file.name,
    size: file.size,
    extension: getFileExtension(file.name),
    addedAt: Date.now(),
    source: file,
    status: error ? 'error' : 'ready',
    progress: 0,
    ...(error ? { errorMessage: error } : {}),
  }
}
