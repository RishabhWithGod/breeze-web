import { useCallback } from 'react'
import { useDropzone, type Accept, type FileRejection } from 'react-dropzone'
import { AnimatePresence, motion } from 'framer-motion'
import { FilePlus2, FileText, Trash2, TriangleAlert } from 'lucide-react'
import { Button, IconButton, TextInput } from '@/components/common'
import { MAX_FILES, MAX_FILE_SIZE_MB } from '@/constants'
import type { RejectedUploadFile } from '@/types'
import { cn, formatFileSize } from '@/utils'

/** PDF only: a project's drawing set is defined as PDFs. */
const PDF_ACCEPT: Accept = { 'application/pdf': ['.pdf'] }

export interface ProjectPdfPickerProps {
  files: readonly File[]
  /** Labels, positionally matched to `files`. */
  titles: readonly string[]
  onAdd: (files: File[]) => void
  onRemove: (index: number) => void
  onTitleChange: (index: number, title: string) => void
  /** Reported when the browser refuses a file outright. */
  onReject?: (rejected: RejectedUploadFile[]) => void
  /** Live limits from the server; falls back to the mirrored constants. */
  maxFiles?: number
  maxFileSizeMb?: number
  disabled?: boolean
  /** Server error for the set as a whole (`documents`). */
  error?: string
  /** Server errors for individual files, keyed `documents.0`. */
  fileErrors?: Readonly<Record<string, string>>
  className?: string
}

/**
 * Defines a project's drawing PDFs: drop them in, then give each one a label.
 *
 * The queue is local — nothing is uploaded until the surrounding form is
 * submitted — and the label is what the project screen shows for the drawing, so
 * "E-101.pdf" can read as "Ground floor lighting".
 */
export function ProjectPdfPicker({
  files,
  titles,
  onAdd,
  onRemove,
  onTitleChange,
  onReject,
  maxFiles = MAX_FILES,
  maxFileSizeMb = MAX_FILE_SIZE_MB,
  disabled = false,
  error,
  fileErrors,
  className,
}: ProjectPdfPickerProps) {
  const remaining = Math.max(0, maxFiles - files.length)

  const onDrop = useCallback(
    (accepted: File[], rejections: FileRejection[]) => {
      // The dropzone's own `maxFiles` counts a single drop, not the queue, so the
      // running total is enforced here.
      if (accepted.length > 0) onAdd(accepted.slice(0, remaining))

      const overflow = accepted.slice(remaining).map((file) => ({
        name: file.name,
        reason: `Only ${maxFiles} PDFs can be added`,
      }))

      const refused = rejections.map((rejection) => ({
        name: rejection.file.name,
        reason:
          rejection.errors[0]?.code === 'file-too-large'
            ? `Exceeds the ${maxFileSizeMb} MB limit`
            : 'Only PDF drawings can be added',
      }))

      if (overflow.length + refused.length > 0) onReject?.([...overflow, ...refused])
    },
    [onAdd, onReject, remaining, maxFiles, maxFileSizeMb],
  )

  const { getRootProps, getInputProps, isDragActive, isDragReject, open } = useDropzone({
    onDrop,
    accept: PDF_ACCEPT,
    maxSize: maxFileSizeMb * 1024 * 1024,
    disabled: disabled || remaining === 0,
    noClick: true,
    noKeyboard: true,
  })

  const isRejecting = isDragReject || Boolean(error)
  const totalSize = files.reduce((total, file) => total + file.size, 0)

  return (
    <div className={cn('space-y-4', className)}>
      <div
        {...getRootProps()}
        data-testid="project-pdf-dropzone"
        aria-disabled={disabled || remaining === 0}
        className={cn(
          'rounded-dropzone border-2 border-dashed p-6 text-center transition-colors duration-300 sm:p-8',
          'focus-within:border-brand',
          remaining === 0 || disabled ? 'cursor-not-allowed opacity-60' : 'cursor-copy',
          isRejecting
            ? 'border-status-danger bg-status-danger/10'
            : isDragActive
              ? 'border-brand-soft bg-brand/12'
              : files.length > 0
                ? 'border-status-success/70 bg-status-success/6'
                : 'border-brand bg-white/4 hover:bg-white/8',
        )}
      >
        <input {...getInputProps()} aria-label="Choose drawing PDFs" />

        <span
          className={cn(
            'mx-auto mb-3 grid size-14 place-items-center rounded-full',
            isRejecting ? 'bg-status-danger/20 text-red-300' : 'bg-brand/15 text-brand',
          )}
        >
          {isRejecting ? (
            <TriangleAlert size={26} aria-hidden />
          ) : (
            <FilePlus2 size={26} aria-hidden />
          )}
        </span>

        <p className="text-lg font-semibold text-white">
          {isRejecting
            ? 'Only PDF drawings can be added'
            : isDragActive
              ? 'Drop the PDFs here'
              : 'Drag & drop the project PDFs'}
        </p>

        <Button
          type="button"
          size="sm"
          disabled={disabled || remaining === 0}
          onClick={open}
          className="mt-3"
        >
          Browse PDFs
        </Button>

        <p className="mt-4 text-sm text-white/80">
          {remaining === 0
            ? `All ${maxFiles} slots used`
            : `${remaining} of ${maxFiles} remaining · max ${maxFileSizeMb} MB each`}
        </p>
      </div>

      {error && <p className="text-sm text-red-300">{error}</p>}

      {files.length > 0 && (
        <div>
          <div className="mb-3 flex items-center justify-between">
            <p className="text-md font-medium text-white">
              Drawings
              <span className="ml-2 rounded-full bg-white/10 px-2 py-0.5 text-xs text-white/90">
                {files.length}
              </span>
            </p>
            <p className="text-sm text-white/75">{formatFileSize(totalSize)} total</p>
          </div>

          <ul className="space-y-3">
            <AnimatePresence initial={false}>
              {files.map((file, index) => (
                <motion.li
                  key={`${file.name}-${file.lastModified}-${index}`}
                  layout
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, x: -12 }}
                  transition={{ duration: 0.2 }}
                  className="rounded-panel border border-hairline bg-white/4 p-4"
                >
                  <div className="flex items-start gap-3">
                    <span className="grid size-9 shrink-0 place-items-center rounded-sm bg-ocean-600 text-white">
                      <FileText size={16} aria-hidden />
                    </span>

                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium text-white">{file.name}</p>
                      <p className="mt-0.5 text-sm text-white/75">
                        PDF · {formatFileSize(file.size)}
                      </p>
                    </div>

                    <IconButton
                      icon={Trash2}
                      label={`Remove ${file.name}`}
                      variant="white"
                      size="sm"
                      disabled={disabled}
                      onClick={() => onRemove(index)}
                      className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
                    />
                  </div>

                  <div className="mt-3">
                    <TextInput
                      id={`document-title-${index}`}
                      label="What this drawing shows (optional)"
                      placeholder="e.g. Ground floor lighting plan"
                      maxLength={120}
                      value={titles[index] ?? ''}
                      onChange={(event) => onTitleChange(index, event.target.value)}
                      disabled={disabled}
                      {...(fileErrors?.[`documents.${index}`]
                        ? { error: fileErrors[`documents.${index}`] }
                        : {})}
                    />
                  </div>
                </motion.li>
              ))}
            </AnimatePresence>
          </ul>
        </div>
      )}
    </div>
  )
}
