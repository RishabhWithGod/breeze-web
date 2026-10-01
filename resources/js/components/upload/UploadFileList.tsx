import { AnimatePresence, motion } from 'framer-motion'
import type { UploadFile } from '@/types'
import { cn } from '@/utils'
import { FileListItem } from './FileListItem'

export interface UploadFileListProps {
  files: readonly UploadFile[]
  onRemove: (id: string) => void
  onReplace: (id: string, file: File) => void
  disabled?: boolean
  className?: string
}

/** Queued files as a table — file, validation status, actions. */
export function UploadFileList({
  files,
  onRemove,
  onReplace,
  disabled = false,
  className,
}: UploadFileListProps) {
  if (files.length === 0) return null

  return (
    <motion.div
      layout
      className={cn('overflow-hidden rounded-panel border border-hairline', className)}
    >
      <div className="hidden grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_6rem] gap-3 bg-ocean-700/40 px-4 py-3 text-sm font-semibold text-white sm:grid">
        <span>File Name</span>
        <span>Status</span>
        <span className="text-right">Actions</span>
      </div>

      <ul>
        <AnimatePresence initial={false}>
          {files.map((file) => (
            <FileListItem
              key={file.id}
              file={file}
              onRemove={onRemove}
              onReplace={onReplace}
              disabled={disabled}
            />
          ))}
        </AnimatePresence>
      </ul>
    </motion.div>
  )
}
