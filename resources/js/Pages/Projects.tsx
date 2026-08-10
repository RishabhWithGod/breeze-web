import { useCallback, useEffect, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { FolderKanban, Plus, SearchX } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  FilterTabs,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
} from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import { ProjectListCard } from '@/components/projects'
import {
  PROJECT_SORT_OPTIONS,
  PROJECT_STATUS_FILTERS,
  ROUTES,
  routeTo,
  type ProjectSort,
  type ProjectStatusFilter,
} from '@/constants'
import { useDebouncedValue, useDisclosure } from '@/hooks'
import type { Paginated, ProjectListRow, SharedPageProps, TableColumn } from '@/types'
import {
  JOB_TYPE_LABEL,
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  formatDate,
  formatNumber,
} from '@/utils'

interface ProjectFilters {
  search: string
  status: ProjectStatusFilter
  sort: ProjectSort
}

export interface ProjectsProps {
  projects: Paginated<ProjectListRow>
  filters: ProjectFilters
  counts: {
    total: number
    drafts: number
    documents: number
  }
}

/**
 * Projects.
 *
 * The record a takeoff, an estimate and a job are all raised against. Search,
 * status filter, sort and pagination are query-string driven, so the database
 * does the work and every view is a shareable URL.
 */
export default function Projects({ projects, filters, counts }: ProjectsProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [pendingDelete, setPendingDelete] = useState<ProjectListRow | null>(null)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const deleteDialog = useDisclosure()
  const debouncedQuery = useDebouncedValue(query)

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  /** Reload with changed filters, leaving scroll position and focus alone. */
  const applyFilters = useCallback(
    (changes: Partial<ProjectFilters & { page: number }>) => {
      router.get(
        ROUTES.projects,
        { ...filters, ...changes },
        { preserveState: true, preserveScroll: true, replace: true },
      )
    },
    [filters],
  )

  // Search is debounced so typing doesn't fire a request per keystroke.
  useEffect(() => {
    if (debouncedQuery === filters.search) return
    applyFilters({ search: debouncedQuery })
  }, [debouncedQuery, filters.search, applyFilters])

  const requestDelete = useCallback(
    (project: ProjectListRow) => {
      setPendingDelete(project)
      deleteDialog.open()
    },
    [deleteDialog],
  )

  const handleDeleteConfirmed = useCallback(() => {
    if (!pendingDelete) return

    router.delete(routeTo.project(pendingDelete.id), { preserveScroll: true })
    setPendingDelete(null)
    deleteDialog.close()
  }, [pendingDelete, deleteDialog])

  const resetFilters = useCallback(() => {
    setQuery('')
    applyFilters({ search: '', status: 'all' })
  }, [applyFilters])

  const columns: TableColumn<ProjectListRow>[] = [
    {
      key: 'name',
      header: 'Project',
      render: (row) => (
        <div className="flex items-center gap-3">
          <span className="grid size-8 shrink-0 place-items-center rounded-sm bg-ocean-600 text-white">
            <FolderKanban size={15} aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="font-bold text-white">{row.name}</p>
            {/* Only shown when the client uses a project number. */}
            {row.code && <p className="text-sm text-white/75">{row.code}</p>}
          </div>
        </div>
      ),
    },
    {
      key: 'client',
      header: 'Client',
      render: (row) => (
        <div className="min-w-0">
          <p className="text-white/90">{row.client}</p>
          {row.location && <p className="text-sm text-white/70">{row.location}</p>}
        </div>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      render: (row) => (
        <span className="whitespace-nowrap text-white/90">
          {row.projectType ? JOB_TYPE_LABEL[row.projectType] : row.discipline}
        </span>
      ),
    },
    {
      key: 'documents',
      header: 'Drawings',
      width: 'w-24',
      render: (row) => (
        <span className="tabular-nums text-white">{formatNumber(row.documentsCount)}</span>
      ),
    },
    {
      key: 'due',
      header: 'Due',
      render: (row) => (
        <span className="whitespace-nowrap text-white/90">
          {row.dueDate ? formatDate(row.dueDate) : '—'}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <StatusChip
          hideDot
          tone={TAKEOFF_STATUS_TONE[row.status]}
          label={TAKEOFF_STATUS_LABEL[row.status]}
        />
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-44',
      render: (row) => (
        <div className="flex items-center gap-2">
          <ButtonLink href={routeTo.project(row.id)} size="sm">
            Open
          </ButtonLink>
          <Button
            variant="white"
            size="sm"
            className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
            onClick={() => requestDelete(row)}
          >
            Delete
          </Button>
        </div>
      ),
    },
  ]

  const rows = projects.data
  const { meta } = projects

  return (
    <PageTransition>
      <Head title="Projects" />

      <section className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <header className="flex flex-col gap-4 border-b border-hairline grad-ocean-soft px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <h1 className="text-2xl font-bold text-white sm:text-3xl">Projects</h1>
            <p className="mt-1 text-md text-white/90">
              {counts.total} {counts.total === 1 ? 'project' : 'projects'} ·{' '}
              {counts.drafts} in draft · {counts.documents}{' '}
              {counts.documents === 1 ? 'drawing' : 'drawings'} on record
            </p>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center lg:shrink-0">
            <SearchBox
              value={query}
              onValueChange={setQuery}
              placeholder="Search name, client, number…"
              containerClassName="sm:w-72"
              aria-label="Search projects"
            />
            <ButtonLink href={ROUTES.projectCreate} variant="dark" leftIcon={Plus}>
              New Project
            </ButtonLink>
          </div>
        </header>

        <div className="p-5 sm:p-6">
          <div className="mb-6 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <FilterTabs
              solid
              options={PROJECT_STATUS_FILTERS}
              value={filters.status}
              onChange={(status) => applyFilters({ status })}
            />

            <div className="flex items-center gap-3">
              <label
                htmlFor="projects-sort"
                className="text-md font-medium whitespace-nowrap text-white/90"
              >
                Sort by:
              </label>
              <SelectField
                id="projects-sort"
                options={PROJECT_SORT_OPTIONS}
                value={filters.sort}
                onChange={(event) =>
                  applyFilters({ sort: event.target.value as ProjectSort })
                }
                className="w-52"
              />
            </div>
          </div>

          <AnimatePresence initial={false}>
            {notice && (
              <Alert
                key={notice}
                tone={flash.warning ? 'warning' : 'success'}
                className="mb-5"
                onDismiss={() => setDismissed(notice)}
              >
                {notice}
              </Alert>
            )}
          </AnimatePresence>

          {rows.length === 0 ? (
            <EmptyState
              icon={filters.search || filters.status !== 'all' ? SearchX : FolderKanban}
              title={
                filters.search || filters.status !== 'all'
                  ? 'No projects found'
                  : 'No projects yet'
              }
              description={
                filters.search || filters.status !== 'all'
                  ? 'No projects match your current filters. Try another status or clear the search.'
                  : 'Create a project to hold its details and its drawing PDFs.'
              }
              actions={
                filters.search || filters.status !== 'all' ? (
                  <Button variant="secondary" onClick={resetFilters}>
                    Reset filters
                  </Button>
                ) : (
                  <ButtonLink href={ROUTES.projectCreate} leftIcon={Plus}>
                    New Project
                  </ButtonLink>
                )
              }
            />
          ) : (
            <>
              {/* Table view — xl and up, where all seven columns fit the panel */}
              <div className="hidden xl:block">
                <Table
                  dense
                  variant="lined"
                  headerVariant="plain"
                  columns={columns}
                  rows={rows}
                  getRowId={(row) => row.id}
                  caption="Projects on record"
                />
              </div>

              {/* Card view — below xl, so every field stays reachable. */}
              <ul className="space-y-3 xl:hidden">
                {rows.map((row, rowIndex) => (
                  <ProjectListCard
                    key={row.id}
                    project={row}
                    index={rowIndex}
                    onDelete={requestDelete}
                  />
                ))}
              </ul>
            </>
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => applyFilters({ page })}
            summary={
              meta.total === 0
                ? 'No projects to display'
                : `Showing ${rows.length} of ${meta.total} projects`
            }
          />
        </div>
      </section>

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Delete “${pendingDelete?.name ?? ''}”?`}
        description="The project is removed from your list. Its drawings stay on file."
        confirmLabel="Delete project"
        confirmVariant="danger"
        onConfirm={handleDeleteConfirmed}
        onCancel={() => {
          setPendingDelete(null)
          deleteDialog.close()
        }}
      />
    </PageTransition>
  )
}

Projects.layout = appLayout
