import { useCallback, useEffect, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  Archive,
  ArchiveRestore,
  Download,
  Eye,
  FilePlus2,
  FolderPlus,
  History as HistoryIcon,
  SearchX,
  Share2,
  SlidersHorizontal,
  Sparkles,
  Star,
  Upload as UploadIcon,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  EmptyState,
  FilterTabs,
  MoreMenu,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
  ConfirmDialog,
} from '@/components/common'
import {
  DocumentHistoryModal,
  NewFolderModal,
  ShareDocumentModal,
  UploadVersionModal,
} from '@/components/documents'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  DOCUMENT_MODIFIED_FILTERS,
  DOCUMENT_TABS,
  DOCUMENT_TYPE_FILTERS,
  DOCUMENT_VERSION_STATUS_FILTERS,
  MOTION,
  ROUTES,
  routeTo,
} from '@/constants'
import { useDebouncedValue, useDisclosure } from '@/hooks'
import type {
  Document,
  DocumentAbilities,
  DocumentFilters,
  DocumentFolderOption,
  DocumentJobOption,
  DocumentTab,
  DocumentTabCounts,
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
  jobs: readonly DocumentJobOption[]
  folders: readonly DocumentFolderOption[]
  tabCounts: DocumentTabCounts
  can: DocumentAbilities
  maxFileSizeMb: number
  shareableUsers: readonly ShareableUser[]
}

/**
 * Documents — every drawing, spec and project file in one place: filterable,
 * versioned, favoritable and archivable, filed against real jobs, estimates
 * and folders. Nothing here is a mock row; every action persists.
 */
export default function Documents({
  documents,
  filters,
  jobs,
  folders,
  tabCounts,
  can,
  maxFileSizeMb,
  shareableUsers,
}: DocumentsProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [draft, setDraft] = useState(filters)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [activeDocument, setActiveDocument] = useState<Document | null>(null)
  const [pendingDelete, setPendingDelete] = useState<Document | null>(null)

  const filterBar = useDisclosure(true)
  const folderModal = useDisclosure()
  const historyModal = useDisclosure()
  const shareModal = useDisclosure()
  const versionModal = useDisclosure()
  const deleteDialog = useDisclosure()

  const debouncedQuery = useDebouncedValue(query)

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  const rows = documents.data
  const { meta } = documents

  const applyFilters = useCallback((changes: Partial<DocumentFilters & { page: number }>) => {
    const params = new URLSearchParams(window.location.search)

    for (const [key, value] of Object.entries(changes)) {
      // `version_status=all` means "any version" and must stay explicit — the
      // server's default when the key is absent is 'latest', not 'all'.
      if (value === '' || value === null || value === undefined || (value === 'all' && key !== 'version_status')) {
        params.delete(key)
      } else {
        params.set(key, String(value))
      }
    }

    if (!('page' in changes)) params.delete('page')

    const queryString = params.toString()

    router.get(queryString ? `${ROUTES.documents}?${queryString}` : ROUTES.documents, {}, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }, [])

  useEffect(() => {
    if (debouncedQuery === filters.search) return
    applyFilters({ search: debouncedQuery })
  }, [debouncedQuery, filters.search, applyFilters])

  const resetFilters = useCallback(() => {
    setQuery('')
    // 'latest' matches the server's default when `version_status` is absent from the URL below.
    setDraft({ ...filters, search: '', document_type: 'all', job_id: null, modified: 'all', version_status: 'latest' })
    router.get(ROUTES.documents, { tab: filters.tab }, { preserveState: true, preserveScroll: true, replace: true })
  }, [filters])

  const changeTab = (tab: DocumentTab) => applyFilters({ tab })

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
        </div>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      render: (document) => <StatusChip hideDot tone="info" label={document.documentType} />,
    },
    {
      key: 'job',
      header: 'Job',
      render: (document) => <span className="text-white/90">{document.jobName ?? '—'}</span>,
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
      <Head title="Documents" />

      <PageHeader
        title="Documents"
        subtitle="Manage project documents, blueprints, and specifications"
        actions={
          <>
            <Button variant="secondary" leftIcon={SlidersHorizontal} aria-expanded={filterBar.isOpen} onClick={filterBar.toggle}>
              Filter
            </Button>
            <Button variant="white" leftIcon={FolderPlus} onClick={folderModal.open}>
              New Folder
            </Button>
            <ButtonLink href={ROUTES.documentsCreate} leftIcon={UploadIcon}>
              Upload Document
            </ButtonLink>
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

      {/* ==================================================== Filters ========= */}
      <AnimatePresence initial={false}>
        {filterBar.isOpen && (
          <motion.div
            key="filter-bar"
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            transition={{ duration: MOTION.base }}
            className="mb-6 overflow-hidden rounded-card border border-hairline glass p-5 shadow-panel sm:p-6"
          >
            <div className="mb-4">
              <SearchBox
                value={query}
                onValueChange={setQuery}
                placeholder="Search Documents…"
                aria-label="Search documents"
                className="max-w-sm"
              />
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <SelectField
                id="document-type-filter"
                label="Document Type"
                options={DOCUMENT_TYPE_FILTERS}
                value={draft.document_type}
                onChange={(event) => setDraft({ ...draft, document_type: event.target.value })}
              />
              <SelectField
                id="document-job-filter"
                label="Jobs"
                options={[{ label: 'All Jobs', value: 'all' }, ...jobs.map((job) => ({ label: job.name, value: String(job.id) }))]}
                value={draft.job_id !== null ? String(draft.job_id) : 'all'}
                onChange={(event) => setDraft({ ...draft, job_id: event.target.value === 'all' ? null : Number(event.target.value) })}
              />
              <SelectField
                id="document-modified-filter"
                label="Date Modified"
                options={DOCUMENT_MODIFIED_FILTERS}
                value={draft.modified}
                onChange={(event) => setDraft({ ...draft, modified: event.target.value as DocumentFilters['modified'] })}
              />
              <SelectField
                id="document-version-filter"
                label="Version Status"
                options={DOCUMENT_VERSION_STATUS_FILTERS}
                value={draft.version_status}
                onChange={(event) => setDraft({ ...draft, version_status: event.target.value as DocumentFilters['version_status'] })}
              />
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-3">
              <Button size="sm" onClick={() => applyFilters({ ...draft })}>
                Apply Filters
              </Button>
              <Button variant="white" size="sm" onClick={resetFilters}>
                Reset
              </Button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* ==================================================== Tabs ============ */}
      <div className="mb-6">
        <FilterTabs options={DOCUMENT_TABS} value={filters.tab} onChange={changeTab} counts={tabCounts} solid />
      </div>

      {/* ================================================= Documents table ===== */}
      <div className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <div className="p-5 sm:p-6">
          {rows.length === 0 ? (
            <EmptyState
              icon={SearchX}
              title="No documents found"
              description="No documents match your current filters. Try another type or clear the search."
              actions={
                <Button variant="secondary" onClick={resetFilters}>
                  Reset filters
                </Button>
              }
            />
          ) : (
            <Table
              dense
              variant="lined"
              headerVariant="plain"
              columns={columns}
              rows={rows}
              getRowId={(document) => document.id}
              caption="Project documents"
            />
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => applyFilters({ page })}
            summary={meta.total === 0 ? 'No documents to display' : `Showing ${rows.length} of ${meta.total} documents`}
          />
        </div>
      </div>

      <NewFolderModal isOpen={folderModal.isOpen} onClose={folderModal.close} jobs={jobs} folders={folders} />
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
