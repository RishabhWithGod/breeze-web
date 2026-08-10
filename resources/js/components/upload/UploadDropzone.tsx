import { useCallback } from 'react'
import { useDropzone, type FileRejection } from 'react-dropzone'
import { motion } from 'framer-motion'
import { CloudUpload, FileWarning, ShieldCheck, UploadCloud } from 'lucide-react'
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
    accept: DROPZONE_ACCEPT,
    maxSize,
    maxFiles,
    disabled,
    noClick: true,
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
        'relative overflow-hidden rounded-dropzone border-2 border-dashed p-8 text-center transition-all duration-300 sm:p-10',
        'focus-within:border-brand',
        disabled && 'cursor-not-allowed opacity-55',
        !disabled && 'cursor-copy',
        isRejecting
          ? 'border-status-danger bg-status-danger/10'
          : isDragActive
            ? 'scale-[1.01] border-brand-soft bg-brand/12 shadow-glow'
            : isSuccess
              ? 'border-status-success/70 bg-status-success/6'
              : 'border-brand bg-white/4 hover:bg-white/8',
        className,
      )}
    >
      <input {...getInputProps()} aria-label="Choose project files" />

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
        className="relative mx-auto mb-4 grid size-20 place-items-center"
      >
        <span
          className={cn(
            'absolute inset-0 rounded-full',
            isRejecting ? 'bg-status-danger/20' : 'bg-brand/15',
            isDragActive && 'animate-pulse-ring',
          )}
          aria-hidden
        />
        <Icon
          size={44}
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
        {isRejecting
          ? 'That file type is not supported'
          : isDragActive
            ? 'Drop to add your drawings'
            : 'Drag & drop files here'}
      </p>

      <p className="mt-1 text-md text-white/80">or</p>

      <Button
        type="button"
        size="sm"
        variant="primary"
        disabled={disabled}
        onClick={open}
        className="mt-3"
      >
        Browse Files
      </Button>

      <div className="mt-6 flex flex-wrap items-center justify-center gap-2">
        {SUPPORTED_FORMATS.map((format) => (
          <span
            key={format}
            className="rounded-panel border border-hairline bg-white/8 px-3 py-1 text-xs font-semibold tracking-wider text-white"
          >
            {format}
          </span>
        ))}
      </div>

      <p className="mt-4 flex items-center justify-center gap-2 text-sm text-white/80">
        <ShieldCheck size={15} aria-hidden className="text-brand/80" />
        Up to {maxFiles} files · max {maxFileSizeMb} MB each
      </p>
    </div>
  )
}
