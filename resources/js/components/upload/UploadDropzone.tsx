import { useCallback } from 'react'
import { useDropzone, type Accept, type FileRejection } from 'react-dropzone'
import { motion } from 'framer-motion'
import { CloudUpload, FileWarning, UploadCloud } from 'lucide-react'
import { Button } from '@/components/common'
import {
  DROPZONE_ACCEPT,
  MAX_FILES,
  MAX_FILE_SIZE_MB,
  SUPPORTED_FORMATS,
} from '@/constants'
import type { RejectedUploadFile } from '@/types'
import { cn } from '@/utils'

export interface UploadDropzoneProps {
  onFilesAccepted: (files: File[]) => void
  onFilesRejected?: (rejected: RejectedUploadFile[]) => void
  /** Live limits from the server; falls back to the mirrored constants. */
  maxFiles?: number
  maxFileSizeMb?: number
  /** Overrides which MIME/extensions are accepted; defaults to the AI Takeoff set. */
  accept?: Accept
  /** Overrides the format badges shown below the dropzone. */
  supportedFormats?: readonly string[]
  /** Blocks interaction while an upload is in flight. */
  disabled?: boolean
  /** Renders the invalid styling (used when the parent reports a form error). */
  hasError?: boolean
  /** Renders the confirmed styling once files are queued. */
  isSuccess?: boolean
  className?: string
}

/**
 * Drag & drop surface with hover, active-drag, reject, disabled, error and
 * success states. All state is local — nothing is uploaded.
 */
export function UploadDropzone({
  onFilesAccepted,
  onFilesRejected,
  maxFiles = MAX_FILES,
  maxFileSizeMb = MAX_FILE_SIZE_MB,
  accept = DROPZONE_ACCEPT,
  supportedFormats = SUPPORTED_FORMATS,
  disabled = false,
  hasError = false,
  isSuccess = false,
  className,
}: UploadDropzoneProps) {
  const maxSize = maxFileSizeMb * 1024 * 1024

  const onDrop = useCallback(
    (accepted: File[], rejections: FileRejection[]) => {
      if (accepted.length > 0) onFilesAccepted(accepted)
      if (rejections.length > 0) {
        onFilesRejected?.(
          rejections.map((rejection) => ({
            name: rejection.file.name,
            reason:
              rejection.errors[0]?.code === 'file-too-large'
                ? `Exceeds the ${maxFileSizeMb} MB limit`
                : (rejection.errors[0]?.message ?? 'File was rejected'),
          })),
        )
      }
    },
    [onFilesAccepted, onFilesRejected, maxFileSizeMb],
  )

  const { getRootProps, getInputProps, isDragActive, isDragReject, open } = useDropzone({
    onDrop,
    accept,
    maxSize,
    maxFiles,
    multiple: maxFiles > 1,
    disabled,
    noClick: false,
    noKeyboard: true,
  })

  const isRejecting = isDragReject || hasError
  const Icon = isRejecting ? FileWarning : isDragActive ? UploadCloud : CloudUpload

  return (
    <div
      {...getRootProps()}
      data-testid="upload-dropzone"
      aria-disabled={disabled}
      className={cn(
        'relative overflow-hidden rounded-dropzone border border-dashed p-8 text-center transition-all duration-300 sm:px-10 sm:py-12',
        'focus-within:border-brand',
        disabled && 'cursor-not-allowed opacity-55',
        !disabled && 'cursor-copy',
        isRejecting
          ? 'border-status-danger bg-status-danger/10'
          : isDragActive
            ? 'scale-[1.01] border-brand-soft bg-brand/12 shadow-glow'
            : isSuccess
              ? 'border-status-success/70 bg-status-success/6'
              : 'border-brand/70 bg-brand/4 hover:bg-brand/8',
        className,
      )}
    >
      <input {...getInputProps()} aria-label="Choose client files" />

      {/* Scanning beam shown while a drag is in progress. */}
      {isDragActive && !isRejecting && (
        <span
          className="pointer-events-none absolute inset-x-0 top-0 h-16 animate-scan bg-linear-to-b from-brand/35 to-transparent"
          aria-hidden
        />
      )}

      <motion.div
        animate={isDragActive ? { scale: 1.06 } : { scale: 1 }}
        transition={{ type: 'spring', stiffness: 320, damping: 22 }}
        className="relative mx-auto mb-3 grid size-16 place-items-center"
      >
        {(isRejecting || isDragActive) && (
          <span
            className={cn(
              'absolute inset-0 rounded-full',
              isRejecting ? 'bg-status-danger/20' : 'bg-brand/15 animate-pulse-ring',
            )}
            aria-hidden
          />
        )}
        <Icon
          size={52}
          aria-hidden
          className={cn(
            'relative transition-colors',
            isRejecting
              ? 'text-red-300'
              : isSuccess
                ? 'text-status-success'
                : 'text-brand',
          )}
        />
      </motion.div>

      <p className="text-xl font-semibold text-white">
        {isDragReject
          ? 'That file type is not supported'
          : hasError
            ? 'Resolve the message above to continue'
            : isDragActive
              ? 'Drop to add your drawings'
              : `Drag and drop ${supportedFormats.length === 1 ? `${supportedFormats[0]} ` : ''}files here`}
      </p>

      <p className="mt-1 text-md text-white/85">or click to browse your files</p>

      <Button
        type="button"
        size="sm"
        variant="primary"
        disabled={disabled}
        onClick={(event) => {
          event.stopPropagation()
          open()
        }}
        className="mt-5"
      >
        Browse Files
      </Button>

      <p className="mt-5 text-sm text-white/80">
        Supported format: {supportedFormats.join(', ')}
        <span className="mx-2 text-white/40">|</span>
        {maxFiles === 1 ? 'One file at a time' : `Up to ${maxFiles} files`}
        <span className="mx-2 text-white/40">|</span>
        Maximum file size: {maxFileSizeMb} MB per file
      </p>
    </div>
  )
}
