import { useEffect, useState } from 'react'
import { Download, Eye } from 'lucide-react'
import { Button, Modal, StatusChip, Table } from '@/components/common'
import type { Document, TableColumn } from '@/types'
import { formatFileSize, formatModified } from '@/utils'

export interface DocumentHistoryModalProps {
  isOpen: boolean
  onClose: () => void
  document: Document | null
}

/** Fetches the real version family for one document and lists it. */
export function DocumentHistoryModal({ isOpen, onClose, document: doc }: DocumentHistoryModalProps) {
  const [versions, setVersions] = useState<Document[] | null>(null)

  useEffect(() => {
    if (!isOpen || !doc) return undefined

    let cancelled = false

    fetch(doc.historyUrl, { headers: { Accept: 'application/json' } })
      .then((response) => response.json())
      .then((payload: { data: Document[] }) => {
        if (!cancelled) setVersions(payload.data)
      })

    return () => {
      cancelled = true
    }
  }, [isOpen, doc])

  const columns: TableColumn<Document>[] = [
    { key: 'version', header: 'Version', render: (row) => <span className="font-semibold text-white">{row.versionLabel}</span> },
    { key: 'filename', header: 'Filename', render: (row) => <span className="text-white/90">{row.originalFilename}</span> },
    { key: 'uploader', header: 'Uploaded By', render: (row) => <span className="text-white/85">{row.uploaderName ?? '—'}</span> },
    { key: 'date', header: 'Date', render: (row) => <span className="text-white/80">{formatModified(row.uploadedAt)}</span> },
    { key: 'size', header: 'Size', render: (row) => <span className="text-white/80">{formatFileSize(row.fileSize)}</span> },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (row.isLatest ? <StatusChip hideDot tone="success" label="Latest" /> : <StatusChip hideDot tone="neutral" label="Superseded" />),
    },
    {
      key: 'actions',
      header: 'Actions',
      render: (row) => (
        <div className="flex items-center gap-2">
          <Button variant="white" size="sm" leftIcon={Eye} onClick={() => window.open(row.previewUrl, '_blank')}>
            Open
          </Button>
          <Button variant="white" size="sm" leftIcon={Download} onClick={() => window.open(row.downloadUrl, '_blank')}>
            Download
          </Button>
        </div>
      ),
    },
  ]

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={doc ? `Version History — ${doc.name}` : 'Version History'}
      size="xl"
    >
      {versions === null ? (
        <p className="text-md text-white/80">Loading version history…</p>
      ) : (
        <Table
          dense
          variant="lined"
          headerVariant="plain"
          columns={columns}
          rows={versions}
          getRowId={(row) => row.id}
          caption="Document version history"
        />
      )}
    </Modal>
  )
}
