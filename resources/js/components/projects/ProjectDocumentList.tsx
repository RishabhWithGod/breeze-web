import { motion } from 'framer-motion'
import { ExternalLink, FileText, Trash2 } from 'lucide-react'
import { Badge, EmptyState, IconButton } from '@/components/common'
import type { ProjectDocument } from '@/types'
import { cn, formatDate, formatFileSize } from '@/utils'

export interface ProjectDocumentListProps {
  documents: readonly ProjectDocument[]
  /** Omitted where the list is read-only. */
  onRemove?: (document: ProjectDocument) => void
  disabled?: boolean
  className?: string
}

/** The PDFs on record for a project, each opening in the browser's own viewer. */
export function ProjectDocumentList({
  documents,
  onRemove,
  disabled = false,
  className,
}: ProjectDocumentListProps) {
  if (documents.length === 0) {
    return (
      <EmptyState
        size="sm"
        icon={FileText}
        title="No drawings yet"
        description="Add the client's PDFs so a takeoff can be run against them."
        className={className}
      />
    )
  }

  return (
    <ul className={cn('space-y-3', className)}>
      {documents.map((document, index) => (
        <motion.li
          key={document.id}
          initial={{ opacity: 0, y: 8 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
          transition={{ duration: 0.3, delay: Math.min(index, 6) * 0.05 }}
          className="flex items-start gap-3 rounded-panel border border-hairline bg-white/4 p-4 transition-colors hover:border-brand/35"
        >
          <span className="grid size-9 shrink-0 place-items-center rounded-sm bg-ocean-600 text-white">
            <FileText size={16} aria-hidden />
          </span>

          <div className="min-w-0 flex-1">
            <p className="truncate font-medium text-white">{document.label}</p>
            <p className="mt-0.5 truncate text-sm text-white/75">
              {/* The file name is worth repeating only when a label replaced it. */}
              {document.title ? `${document.name} · ` : ''}
              {formatFileSize(document.sizeBytes)}
              {document.pageCount > 0 && ` · ${document.pageCount} pages`}
              {` · added ${formatDate(document.uploadedAt)}`}
            </p>
          </div>

          {document.available && document.url ? (
            <a
              href={document.url}
              target="_blank"
              rel="noreferrer"
              className="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-brand hover:underline"
            >
              <ExternalLink size={14} aria-hidden />
              Open
            </a>
          ) : (
            <Badge tone="warning" size="sm" className="shrink-0">
              File missing
            </Badge>
          )}

          {onRemove && (
            <IconButton
              icon={Trash2}
              label={`Remove ${document.label}`}
              variant="white"
              size="sm"
              disabled={disabled}
              onClick={() => onRemove(document)}
              className="shrink-0 text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
            />
          )}
        </motion.li>
      ))}
    </ul>
  )
}
