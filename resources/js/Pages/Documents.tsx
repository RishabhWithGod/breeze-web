import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  Archive,
  ArchiveRestore,
  ArrowLeft,
  Download,
  Eye,
  FilePlus2,
  History as HistoryIcon,
  SearchX,
  Share2,
  Sparkles,
  Star,
  Upload as UploadIcon,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  Table,
} from '@/components/common'
import {
  DocumentHistoryModal,
  ShareDocumentModal,
  UploadVersionModal,
} from '@/components/documents'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  ROUTES,
  routeTo,
} from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  Document,
  DocumentAbilities,
  DocumentFilters,
  Paginated,
  SharedPageProps,
  TableColumn,
} from '@/types'
import { formatModified } from '@/utils'

interface ShareableUser {
  readonly id: number
  readonly name: string
}

export interface DocumentsProps {
  documents: Paginated<Document>
  filters: DocumentFilters
  documentTypes: readonly string[]
  can: DocumentAbilities
  maxFileSizeMb: number
  shareableUsers: readonly ShareableUser[]
  /**
   * The takeoff this list belongs to, when it was opened from one. Null means
   * the whole workspace's paperwork rather than one takeoff's.
   */
  takeoff: {
    readonly id: number
    readonly name: string
    readonly clientName: string | null
    readonly url: string
  } | null
}

/**
 * Documents — every drawing, spec and project file in one place: filterable,
 * versioned, favoritable and archivable, filed against real jobs, estimates
 * Nothing here is a mock row; every action persists.
 */
export default function Documents({
  documents,
  can,
  maxFileSizeMb,
  shareableUsers,
  takeoff,
}: DocumentsProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [dismissed, setDismissed] = useState<string | null>(null)
  const [activeDocument, setActiveDocument] = useState<Document | null>(null)
  const [pendingDelete, setPendingDelete] = useState<Document | null>(null)

  const historyModal = useDisclosure()
  const shareModal = useDisclosure()
  const versionModal = useDisclosure()
  const deleteDialog = useDisclosure()

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  const rows = documents.data
  const { meta } = documents

  /**
   * Merged into the current query string rather than replacing it, so the
   * takeoff this list belongs to survives a page turn.
   */
  const goToPage = useCallback((page: number) => {
    const params = new URLSearchParams(window.location.search)
    params.set('page', String(page))

    router.get(`${ROUTES.documents}?${params.toString()}`, {}, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }, [])

  const toggleFavorite = (document: Document) => {
    router.post(routeTo.documentFavorite(document.id), {}, { preserveScroll: true })
  }

  const openHistory = (document: Document) => {
    setActiveDocument(document)
    historyModal.open()
  }

  const openShare = (document: Document) => {
    setActiveDocument(document)
    shareModal.open()
  }

  const openVersionUpload = (document: Document) => {
    setActiveDocument(document)
    versionModal.open()
  }

  const requestDelete = (document: Document) => {
    setPendingDelete(document)
    deleteDialog.open()
  }

  const confirmDelete = () => {
    if (!pendingDelete) return
    router.delete(routeTo.document(pendingDelete.id), { preserveScroll: true })
    setPendingDelete(null)
    deleteDialog.close()
  }

  const toggleArchive = (document: Document) => {
    const url = document.isArchived ? routeTo.documentRestore(document.id) : routeTo.documentArchive(document.id)
    router.post(url, {}, { preserveScroll: true })
  }

  const columns: TableColumn<Document>[] = [
    {
      key: 'name',
      header: 'Name',
      render: (document) => (
        <div className="flex items-center gap-2">
          <button
            type="button"
            aria-label={document.isFavorite ? 'Unfavorite' : 'Favorite'}
            onClick={() => toggleFavorite(document)}
            className="shrink-0 text-white/60 transition-colors hover:text-amber-300"
          >
            <Star size={15} aria-hidden fill={document.isFavorite ? 'currentColor' : 'none'} className={document.isFavorite ? 'text-amber-300' : ''} />
          </button>
          <button
            type="button"
            onClick={() => window.open(document.previewUrl, '_blank')}
            className="max-w-xs truncate text-left font-semibold text-white hover:underline"
            title={document.name}
          >
            {document.name}
          </button>
          {document.aiTakeoffProjectId && (
            <span title="Tracked by AI Takeoff" className="shrink-0 text-brand">
              <Sparkles size={14} aria-hidden />
            </span>
          )}
          {/*
            The list has no tabs any more, so an archived document is shown
            here rather than filed behind one — marked, so it reads as put
            away rather than current, and still restorable from its row.
          */}
          {document.isArchived && (
            <Badge tone="neutral" size="sm" className="shrink-0">
              Archived
            </Badge>
          )}
        </div>
      ),
    },

    {
      key: 'modified',
      header: 'Modified',
      render: (document) => (
        <span className="whitespace-nowrap text-white/85">{formatModified(document.modifiedAt)}</span>
      ),
    },
    {
      key: 'version',
      header: 'Version',
      render: (document) => (
        <span className={document.isLatest ? 'font-semibold text-status-success' : 'text-white/75'}>
          {document.versionLabel}
        </span>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-56',
      render: (document) => (
        <div className="flex items-center gap-2">
          <Button variant="white" size="sm" leftIcon={HistoryIcon} onClick={() => openHistory(document)}>
            History
          </Button>
          <Button
            variant="white"
            size="sm"
            disabled={!document.canDelete}
            className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white disabled:opacity-50"
            onClick={() => requestDelete(document)}
          >
            Delete
          </Button>
          <MoreMenu
            ariaLabel={`More actions for ${document.name}`}
            items={[
              { label: 'Open / Preview', icon: Eye, onSelect: () => window.open(document.previewUrl, '_blank') },
              { label: 'Download', icon: Download, onSelect: () => window.open(document.downloadUrl, '_blank') },
              ...(document.canEdit
                ? [{ label: 'Upload New Version', icon: FilePlus2, onSelect: () => openVersionUpload(document) }]
                : []),
              ...(document.canShare ? [{ label: 'Share', icon: Share2, onSelect: () => openShare(document) }] : []),
              ...(document.canDelete
                ? [
                    {
                      label: document.isArchived ? 'Restore' : 'Archive',
                      icon: document.isArchived ? ArchiveRestore : Archive,
                      onSelect: () => toggleArchive(document),
                    },
                  ]
                : []),
            ]}
          />
        </div>
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title={takeoff ? `Documents — ${takeoff.name}` : 'Documents'} />

      <PageHeader
        title="Documents"
        subtitle={
          takeoff
            ? `${takeoff.clientName ? `${takeoff.clientName} — ` : ''}${takeoff.name}`
            : 'Manage client documents, blueprints, and specifications'
        }
        {...(takeoff
          ? {
              breadcrumbs: [
                { label: 'AI Takeoff', href: ROUTES.aiTakeoff },
                { label: takeoff.name, href: takeoff.url },
                { label: 'Documents' },
              ],
            }
          : {})}
        actions={
          <>
            {/* Uploading from a takeoff files the document under it. */}
            <ButtonLink
              href={
                takeoff
                  ? routeTo.projectDocumentCreate(takeoff.id)
                  : ROUTES.documentsCreate
              }
              leftIcon={UploadIcon}
            >
              Upload Document
            </ButtonLink>
            {takeoff && (
              <ButtonLink href={takeoff.url} variant="secondary" leftIcon={ArrowLeft}>
                Back
              </ButtonLink>
            )}
          </>
        }
      />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert key={displayNotice} tone={flash.warning ? 'warning' : 'success'} className="mb-6" onDismiss={() => setDismissed(displayNotice)}>
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      {/* ================================================= Documents table ===== */}
      <div className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <div className="p-5 sm:p-6">
          {rows.length === 0 ? (
            <EmptyState
              icon={SearchX}
              title="Nothing filed here yet"
              description="Upload a contract, submittal or photo and it will appear here."
            />
          ) : (
            <Table
              dense
              variant="lined"
              headerVariant="plain"
              columns={columns}
              rows={rows}
              getRowId={(document) => document.id}
              caption="Client documents"
            />
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={goToPage}
            summary={meta.total === 0 ? 'No documents to display' : `Showing ${rows.length} of ${meta.total} documents`}
          />
        </div>
      </div>

      <DocumentHistoryModal
        key={activeDocument?.id ?? 'none'}
        isOpen={historyModal.isOpen}
        onClose={historyModal.close}
        document={activeDocument}
      />
      <ShareDocumentModal isOpen={shareModal.isOpen} onClose={shareModal.close} document={activeDocument} users={shareableUsers} />
      <UploadVersionModal isOpen={versionModal.isOpen} onClose={versionModal.close} document={activeDocument} maxFileSizeMb={maxFileSizeMb} />

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Delete ${pendingDelete?.name ?? ''}?`}
        description={
          pendingDelete && pendingDelete.isLatest && !can.manage
            ? 'This is the latest version. Deleting it promotes the previous version, if one exists.'
            : 'The file and its record are removed permanently.'
        }
        confirmLabel="Delete document"
        confirmVariant="danger"
        onConfirm={confirmDelete}
        onCancel={() => {
          setPendingDelete(null)
          deleteDialog.close()
        }}
      />
    </PageTransition>
  )
}

Documents.layout = appLayout
