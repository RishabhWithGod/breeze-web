import { useRef } from 'react'
import { motion } from 'framer-motion'
import { Check, RefreshCw, Trash2, TriangleAlert } from 'lucide-react'
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
    'grid size-8 place-items-center rounded-panel border border-transparent text-white/85 transition-colors ' +
    'hover:border-brand/60 hover:bg-white/10 hover:text-brand disabled:cursor-not-allowed disabled:opacity-40'

  return (
    <motion.li
      layout
      initial={{ opacity: 0, y: -6 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, height: 0 }}
      transition={{ duration: 0.22 }}
      className={cn(
        'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline px-4 py-3 last:border-b-0',
        'sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_6rem]',
        hasError && 'bg-status-danger/10',
      )}
    >
      <div className="flex min-w-0 items-center gap-3">
        <FileBadge extension={file.extension} />
        <div className="min-w-0">
          <p className="truncate text-md font-semibold text-white" title={file.name}>
            {file.name}
          </p>
          <p className="text-xs text-white/70">{formatFileSize(file.size)}</p>
        </div>
      </div>

      <div className="order-3 col-span-2 min-w-0 sm:order-none sm:col-span-1">
        {hasError ? (
          <div className="flex items-center gap-2.5">
            <TriangleAlert size={22} aria-label="File error" className="shrink-0 text-red-300" />
            <div className="min-w-0">
              <p className="text-sm font-semibold text-red-300">Invalid</p>
              <p className="truncate text-xs text-red-300/90">{file.errorMessage}</p>
            </div>
          </div>
        ) : isUploading ? (
          <ProgressBar value={file.progress} size="sm" />
        ) : (
          <div className="flex items-center gap-2.5">
            <span className="grid size-6 shrink-0 place-items-center rounded-full bg-status-success text-brand-ink">
              <Check size={14} strokeWidth={3} aria-hidden />
            </span>
            <div className="min-w-0">
              <p className="text-sm font-semibold text-status-success">
                {isSuccess ? 'Uploaded' : 'Valid'}
              </p>
              <p className="truncate text-xs text-white/70">
                {file.extension.toUpperCase()} • {formatFileSize(file.size)}
              </p>
            </div>
          </div>
        )}
      </div>

      <div className="flex shrink-0 items-center justify-end gap-1">
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

/** Red "PDF" tile from the reference; other formats keep their own tone. */
function FileBadge({ extension }: { extension: string }) {
  if (extension.toUpperCase() !== 'PDF') return <FileTypeIcon extension={extension} size="sm" />

  return (
    <span className="grid size-10 shrink-0 place-items-center rounded-panel bg-red-500 text-2xs font-bold tracking-wide text-white shadow-panel">
      PDF
    </span>
  )
}
