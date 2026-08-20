import { Download, Eye, FileText } from 'lucide-react'
import { ButtonLink, IconButton } from '@/components/common'
import type { Document } from '@/types'
import { formatModified } from '@/utils'

export interface JobDocumentsPanelProps {
  /** Where "View All Documents" opens — a Documents-module link pre-filtered to this job or estimate. */
  viewAllHref: string
  documents: readonly Document[]
  totalCount: number
  emptyLabel?: string
}

/** A record's own documents, read from the same `documents` table the Documents module manages — never a separate copy. */
export function JobDocumentsPanel({ viewAllHref, documents, totalCount, emptyLabel = 'No documents filed yet.' }: JobDocumentsPanelProps) {
  if (documents.length === 0) {
    return (
      <div>
        <p className="text-md text-white/75">{emptyLabel}</p>
        <ButtonLink href={viewAllHref} variant="secondary" size="sm" className="mt-3">
          Open Documents
        </ButtonLink>
      </div>
    )
  }

  return (
    <div>
      <ul className="space-y-3">
        {documents.map((document) => (
          <li
            key={document.id}
            className="flex items-center gap-3 rounded-panel border border-hairline bg-white/4 p-4"
          >
            <span className="grid size-9 shrink-0 place-items-center rounded-panel bg-brand/15 text-brand">
              <FileText size={16} aria-hidden />
            </span>

            <div className="min-w-0 flex-1">
              <p className="truncate text-md font-medium text-white">{document.name}</p>
              <p className="mt-0.5 text-sm text-white/70">
                {document.documentType} · {document.versionLabel} · {formatModified(document.modifiedAt)}
              </p>
            </div>

            <IconButton
              icon={Eye}
              label={`Preview ${document.name}`}
              size="sm"
              className="shrink-0 text-white/70 hover:text-brand"
              onClick={() => window.open(document.previewUrl, '_blank')}
            />
            <IconButton
              icon={Download}
              label={`Download ${document.name}`}
              size="sm"
              className="shrink-0 text-white/70 hover:text-brand"
              onClick={() => window.open(document.downloadUrl, '_blank')}
            />
          </li>
        ))}
      </ul>

      <ButtonLink href={viewAllHref} variant="secondary" size="sm" className="mt-4">
        View All Documents{totalCount > documents.length ? ` (${totalCount})` : ''}
      </ButtonLink>
    </div>
  )
}
