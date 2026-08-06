import { AnimatePresence, motion } from 'framer-motion'
import { Layers } from 'lucide-react'
import type { UploadFile } from '@/types'
import { cn, formatFileSize } from '@/utils'
import { FileListItem } from './FileListItem'

export interface UploadFileListProps {
  files: readonly UploadFile[]
  onRemove: (id: string) => void
  onReplace: (id: string, file: File) => void
  disabled?: boolean
  className?: string
}

/** Animated queue of selected files with a size summary. */
export function UploadFileList({
  files,
  onRemove,
  onReplace,
  disabled = false,
  className,
}: UploadFileListProps) {
  if (files.length === 0) return null

  const totalSize = files.reduce((total, file) => total + file.size, 0)

  return (
    <motion.div layout className={cn('mt-5', className)}>
      <div className="mb-3 flex items-center justify-between">
        <p className="flex items-center gap-2 text-md font-medium text-white">
          <Layers size={16} aria-hidden className="text-brand" />
          Selected files
          <span className="rounded-full bg-white/10 px-2 py-0.5 text-xs text-white/70">
            {files.length}
          </span>
        </p>
        <p className="text-sm text-white/50">{formatFileSize(totalSize)} total</p>
      </div>

      <ul className="space-y-2">
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
