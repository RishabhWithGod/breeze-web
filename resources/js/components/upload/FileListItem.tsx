import { useRef } from 'react'
import { motion } from 'framer-motion'
import { CheckCircle2, RefreshCw, Trash2, TriangleAlert } from 'lucide-react'
import { ProgressBar } from '@/components/common'
import type { UploadFile } from '@/types'
import { cn, formatFileSize } from '@/utils'
import { FileTypeIcon } from './FileTypeIcon'

export interface FileListItemProps {
  file: UploadFile
  onRemove: (id: string) => void
  onReplace: (id: string, file: File) => void
  disabled?: boolean
}

/** Queued file row with progress, replace and remove affordances. */
export function FileListItem({
  file,
  onRemove,
  onReplace,
  disabled = false,
}: FileListItemProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const hasError = file.status === 'error'
  const isUploading = file.status === 'uploading'
  const isSuccess = file.status === 'success'

  const actionButton =
    'grid size-8 place-items-center rounded-panel border border-hairline text-white/90 transition-colors ' +
    'hover:border-brand/60 hover:bg-white/10 hover:text-brand disabled:cursor-not-allowed disabled:opacity-40'

  return (
    <motion.li
      layout
      initial={{ opacity: 0, x: -12 }}
      animate={{ opacity: 1, x: 0 }}
      exit={{ opacity: 0, x: 12, height: 0 }}
      transition={{ duration: 0.22 }}
      className={cn(
        'flex items-center gap-3 rounded-panel border px-3 py-3',
        hasError
          ? 'border-status-danger/50 bg-status-danger/10'
          : 'border-hairline bg-navy-950/35',
      )}
    >
      <FileTypeIcon extension={file.extension} size="sm" />

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <p className="truncate text-md font-medium text-white" title={file.name}>
            {file.name}
          </p>
          {isSuccess && (
            <CheckCircle2
              size={15}
              aria-label="Upload complete"
              className="shrink-0 text-status-success"
            />
          )}
          {hasError && (
            <TriangleAlert
              size={15}
              aria-label="File error"
              className="shrink-0 text-red-300"
            />
          )}
        </div>

        {hasError ? (
          <p className="mt-0.5 text-sm text-red-300">{file.errorMessage}</p>
        ) : isUploading ? (
          <ProgressBar value={file.progress} size="sm" className="mt-2" />
        ) : (
          <p className="mt-0.5 text-sm text-white/75">
            {formatFileSize(file.size)}
            {isSuccess && ' · ready for analysis'}
          </p>
        )}
      </div>

      <div className="flex shrink-0 items-center gap-1.5">
        <input
          ref={inputRef}
          type="file"
          className="hidden"
          aria-label={`Replace ${file.name}`}
          onChange={(event) => {
            const next = event.target.files?.[0]
            if (next) onReplace(file.id, next)
            event.target.value = ''
          }}
        />
        <button
          type="button"
          className={actionButton}
          disabled={disabled || isUploading}
          onClick={() => inputRef.current?.click()}
          aria-label={`Replace ${file.name}`}
          title="Replace file"
        >
          <RefreshCw size={15} aria-hidden />
        </button>
        <button
          type="button"
          className={cn(actionButton, 'hover:border-status-danger/60 hover:text-red-300')}
          disabled={disabled || isUploading}
          onClick={() => onRemove(file.id)}
          aria-label={`Remove ${file.name}`}
          title="Remove file"
        >
          <Trash2 size={15} aria-hidden />
        </button>
      </div>
    </motion.li>
  )
}
