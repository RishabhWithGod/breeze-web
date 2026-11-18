import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { FolderKanban, PencilLine, Plus, Trash2 } from 'lucide-react'
import {
  Alert,
  AvatarStack,
  Badge,
  ButtonLink,
  Card,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  Table,
  ToolbarSearch,
  UnderlineTabs,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { useDisclosure, usePermissions } from '@/hooks'
import type { Paginated, TableColumn, TakeoffStatus } from '@/types'
import { TAKEOFF_STATUS_LABEL, TAKEOFF_STATUS_TONE, formatDate } from '@/utils'

interface ProjectRow {
  readonly id: number
  readonly name: string
  readonly code: string | null
  readonly status: TakeoffStatus
  readonly projectType: string | null
  /** The client's primary site, snapshotted — see `Project::addresses()`. */
  readonly location: string | null
  readonly drawingCount: number
  readonly takeoffCount: number
  readonly estimateCount: number
  readonly jobCount: number
  readonly teamCount: number
  readonly documentCount: number
  readonly invoiceCount: number
  /** The same two dates the project's own screen calls Start Date and End Date. */
  readonly startedAt: string | null
  readonly dueDate: string | null
  readonly team: readonly { readonly id: number; readonly name: string; readonly initials: string | null }[]
}

/** One client and the projects they have on — the unit this screen pages through. */
interface ClientGroup {
  readonly id: number
  readonly name: string
  readonly projects: readonly ProjectRow[]
}

export interface ProjectsProps {
  clients: Paginated<ClientGroup>
  filters: { readonly search: string; readonly status: string }
  statuses: readonly string[]
  /** Across every client this filter matches, not just the page shown. */
  totalProjects: number
  /** Projects per status over the current search — what each status tab would show. */
  statusCounts: Record<string, number>
}

/** "in-review" reads as a machine value; the label should not. */
const humanise = (value: string) =>
  value.charAt(0).toUpperCase() + value.slice(1).replaceAll('-', ' ')

/** One line of the register: a project under its client, or a client that has none yet. */
type Row =
  | { readonly kind: 'project'; readonly key: string; readonly client: ClientGroup; readonly project: ProjectRow }
  | { readonly kind: 'empty'; readonly key: string; readonly client: ClientGroup }

/**
 * The tabs offered after "All", in order. `active` is not a stored status: it is the projects with work
 * under way (a job planned, scheduled, in progress or late). Statuses left out here — converted, failed,
 * processing — still show under All.
 */
const TAB_ORDER: readonly string[] = ['draft', 'active', 'completed']

const num = (value: number) => <span className="tabular-nums">{value}</span>

/**
 * Every project, under the client it is for.
 *
 * A flat register in the Breeze app's layout — status tabs, a search toolbar and
 * one table — with the client named on every line, because a project's name only
 * means something beside its client. Paged by client, so a client's projects are
 * never split across two pages.
 */
export default function Projects({ clients, filters, statuses, totalProjects, statusCounts }: ProjectsProps) {
  const { can: permitted } = usePermissions()
  const [search, setSearch] = useState(filters.search)
  const [pendingDelete, setPendingDelete] = useState<{ id: number; name: string } | null>(null)
  const deleteDialog = useDisclosure()

  const groups = clients.data
  const narrowed = filters.search !== '' || filters.status !== 'all'
  const allCount = Object.entries(statusCounts).reduce((sum, [key, count]) => (key === 'active' ? sum : sum + count), 0)

  const rows: readonly Row[] = groups.flatMap((client): readonly Row[] =>
    client.projects.length === 0
      ? [{ kind: 'empty', key: `c${client.id}`, client }]
      : client.projects.map((project) => ({ kind: 'project', key: `p${project.id}`, client, project })),
  )

  const apply = (changes: Record<string, string>) => {
    const query = new URLSearchParams(window.location.search)

    for (const [key, value] of Object.entries(changes)) {
      if (value === '' || value === 'all') query.delete(key)
      else query.set(key, value)
    }

    query.delete('page')
    if (changes['page'] !== undefined) query.set('page', changes['page'])

    router.get(`${ROUTES.projects}?${query.toString()}`, undefined, {
      preserveState: true,
      replace: true,
    })
  }

  const columns: readonly TableColumn<Row>[] = [
    {
      key: 'project',
      header: 'Project',
      render: (row) =>
        row.kind === 'project' ? (
          <div className="min-w-0">
            <Link href={routeTo.project(row.project.id)} className="block truncate font-bold text-white hover:text-brand">
              {row.project.name}
            </Link>
            {row.project.location && (
              <p className="truncate text-[13px] text-[var(--text-3)]">{row.project.location}</p>
            )}
          </div>
        ) : (
          <span className="text-[var(--text-3)]">Nothing on for this client yet.</span>
        ),
    },
    {
      key: 'client',
      header: 'Client',
      render: (row) => (
        <Link href={routeTo.client(row.client.id)} className="whitespace-nowrap hover:text-brand">
          {row.client.name}
        </Link>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) =>
        row.kind === 'project' ? (
          <Badge tone={TAKEOFF_STATUS_TONE[row.project.status]}>{TAKEOFF_STATUS_LABEL[row.project.status]}</Badge>
        ) : null,
    },
    {
      key: 'dates',
      header: 'Dates',
      render: (row) => {
        if (row.kind !== 'project') return null
        const { startedAt, dueDate } = row.project

        if (!startedAt && !dueDate) return <span className="text-[var(--text-3)]">—</span>

        return (
          <span className="whitespace-nowrap">
            {startedAt ? formatDate(startedAt) : '—'} – {dueDate ? formatDate(dueDate) : '—'}
          </span>
        )
      },
    },
    {
      key: 'team',
      header: 'Team',
      render: (row) => (row.kind === 'project' ? <AvatarStack people={row.project.team} /> : null),
    },
    { key: 'drawings', header: 'Drawings', align: 'right', render: (row) => (row.kind === 'project' ? num(row.project.drawingCount) : null) },
    { key: 'estimates', header: 'Estimates', align: 'right', render: (row) => (row.kind === 'project' ? num(row.project.estimateCount) : null) },
    {
      key: 'actions',
      header: '',
      align: 'right',
      width: 'w-12',
      cellClassName: 'pl-1',
      render: (row) => {
        if (row.kind === 'empty') {
          return permitted('projects.create') ? (
            <ButtonLink href={routeTo.projectCreateForClient(row.client.id)} variant="ghost" size="sm" leftIcon={Plus}>
              Add
            </ButtonLink>
          ) : null
        }

        const { project } = row
        const items = [
          ...(permitted('projects.edit')
            ? [{ label: 'Edit', icon: PencilLine, onSelect: () => router.visit(routeTo.projectEdit(project.id)) }]
            : []),
          ...(permitted('projects.delete')
            ? [
                {
                  label: 'Delete',
                  icon: Trash2,
                  destructive: true,
                  onSelect: () => {
                    setPendingDelete(project)
                    deleteDialog.open()
                  },
                },
              ]
            : []),
        ]

        return items.length > 0 ? (
          <MoreMenu ariaLabel={`Actions for ${project.name}`} variant="minimal" items={items} />
        ) : null
      },
    },
  ]

  return (
    <PageTransition>
      <Head title="Projects" />

      <PageHeader
        title="Projects"
        subtitle="Manage your projects, drawings, estimates, takeoffs, and job activity - all in one place"
        actions={
          permitted('projects.create') ? (
            <ButtonLink href={ROUTES.projectCreate} leftIcon={Plus}>
              Add project
            </ButtonLink>
          ) : undefined
        }
      />

      <UnderlineTabs
        value={filters.status}
        onChange={(status) => apply({ status })}
        tabs={[
          { key: 'all', label: 'All', count: allCount },
          ...TAB_ORDER.filter((key) => key === 'active' || statuses.includes(key)).map((key) => ({
            key,
            label: key === 'active' ? 'Active' : (TAKEOFF_STATUS_LABEL[key as TakeoffStatus] ?? humanise(key)),
            count: statusCounts[key] ?? 0,
          })),
        ]}
      />

      <Card padding="none" className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b border-hairline px-4 py-3">
          <ToolbarSearch
            value={search}
            onChange={setSearch}
            onSubmit={(value) => apply({ search: value })}
            applied={filters.search !== ''}
            placeholder="Search projects"
          />
        </div>

        {rows.length === 0 ? (
          <EmptyState
            icon={FolderKanban}
            title={narrowed ? 'No project matches' : 'No clients yet'}
            description={
              narrowed
                ? 'Nothing on any client matches these filters.'
                : 'Add the client the work is for, then open a project under them.'
            }
            {...(narrowed || !permitted('clients.create')
              ? {}
              : {
                  actions: (
                    <ButtonLink href={ROUTES.clientCreate} leftIcon={Plus}>
                      Add client
                    </ButtonLink>
                  ),
                })}
          />
        ) : (
          <Table
            variant="lined"
            columns={columns}
            rows={rows}
            getRowId={(row) => row.key}
            caption="Projects"
            onRowClick={(row) => router.visit(row.kind === 'project' ? routeTo.project(row.project.id) : routeTo.client(row.client.id))}
          />
        )}
      </Card>

      {rows.length > 0 && (
        <Alert tone="info" title="Invoices live inside Projects" className="mt-4">
          Create, manage, and track all invoices for each project. Open a project to view its invoices.
        </Alert>
      )}

      <Pagination
        withLabels
        className="mt-5"
        page={clients.meta.current_page}
        pageCount={clients.meta.last_page}
        onPageChange={(page) => apply({ page: String(page) })}
        summary={
          clients.meta.total === 0
            ? 'No clients to display'
            : `${clients.meta.total} ${clients.meta.total === 1 ? 'client' : 'clients'} • ${totalProjects} ${totalProjects === 1 ? 'project' : 'projects'}`
        }
      />

      <ConfirmDialog
        isOpen={deleteDialog.isOpen && pendingDelete !== null}
        tone="danger"
        title={`Delete "${pendingDelete?.name ?? ''}"?`}
        description="The project is removed from your list. Its drawings stay on file."
        confirmLabel="Delete Project"
        confirmVariant="danger"
        onConfirm={() => {
          if (pendingDelete) router.delete(routeTo.project(pendingDelete.id))
          setPendingDelete(null)
          deleteDialog.close()
        }}
        onCancel={() => {
          setPendingDelete(null)
          deleteDialog.close()
        }}
      />
    </PageTransition>
  )
}

Projects.layout = appLayout
