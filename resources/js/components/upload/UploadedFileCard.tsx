import { useRef } from 'react'
import { motion } from 'framer-motion'
import { Check, RefreshCw, TriangleAlert } from 'lucide-react'
import { Button, ProgressBar } from '@/components/common'
import type { UploadFile } from '@/types'
import { cn, formatFileSize } from '@/utils'

export interface UploadedFileCardProps {
  file: UploadFile
  onReplace: (id: string, file: File) => void
  disabled?: boolean
  className?: string
}

/**
 * What the upload card shows once a drawing has been picked, in place of the
 * dropzone: the file's name and details, with "Change" as the only action.
 */
export function UploadedFileCard({
  file,
  onReplace,
  disabled = false,
  className,
}: UploadedFileCardProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const hasError = file.status === 'error'
  const isUploading = file.status === 'uploading'
  const isSuccess = file.status === 'success'
  const extension = file.extension.toUpperCase()

  return (
    <motion.div
      layout
      initial={{ opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.22 }}
      className={cn(
        'rounded-dropzone border p-5 sm:p-6',
        hasError
          ? 'border-status-danger/60 bg-status-danger/10'
          : 'border-brand/50 bg-brand/5',
        className,
      )}
    >
      <div className="flex flex-wrap items-center gap-4">
        <span className="grid size-14 shrink-0 place-items-center rounded-panel bg-red-500 text-sm font-bold tracking-wide text-white shadow-panel">
          {extension}
        </span>

        <div className="min-w-0 flex-1">
          <p className="truncate text-lg font-semibold text-white" title={file.name}>
            {file.name}
          </p>
          <p className="mt-0.5 text-sm text-white/80">
            {extension} • {formatFileSize(file.size)}
          </p>
        </div>

        <input
          ref={inputRef}
          type="file"
          accept={`.${file.extension.toLowerCase()},application/pdf`}
          className="hidden"
          aria-label={`Change ${file.name}`}
          onChange={(event) => {
            const next = event.target.files?.[0]
            if (next) onReplace(file.id, next)
            event.target.value = ''
          }}
        />
        <Button
          type="button"
          variant="secondary"
          size="sm"
          leftIcon={RefreshCw}
          disabled={disabled || isUploading}
          onClick={() => inputRef.current?.click()}
        >
          Change
        </Button>
      </div>

      <div className="mt-4 border-t border-hairline pt-4">
        {hasError ? (
          <p className="flex items-center gap-2 text-sm font-semibold text-red-300">
            <TriangleAlert size={18} aria-hidden />
            {file.errorMessage ?? 'This file could not be used'}
          </p>
        ) : isUploading ? (
          <ProgressBar value={file.progress} size="sm" />
        ) : (
          <p className="flex items-center gap-2 text-sm font-semibold text-status-success">
            <span className="grid size-5 place-items-center rounded-full bg-status-success text-brand-ink">
              <Check size={12} strokeWidth={3} aria-hidden />
            </span>
            {isSuccess ? 'Uploaded' : 'Valid — ready for AI takeoff'}
          </p>
        )}
      </div>
    </motion.div>
  )
}
