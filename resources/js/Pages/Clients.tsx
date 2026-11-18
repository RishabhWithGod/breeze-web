import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { Plus, Search } from 'lucide-react'
import { ButtonLink, Card, EmptyState, Pagination, Table, ToolbarSearch } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { usePermissions } from '@/hooks'
import type { Paginated, TableColumn } from '@/types'
import { formatDate } from '@/utils'

interface ClientRow {
  readonly id: number
  readonly name: string
  /** The client's primary contact, when one's been added. Falls back to the client's own name. */
  readonly contactName: string | null
  readonly contactEmail: string | null
  readonly contactPhone: string | null
  readonly projectCount: number
  readonly siteCount: number
  readonly primarySite: string | null
  /** When the client was added, for "Client since". */
  readonly createdAt: string | null
}

export interface ClientsProps {
  clients: Paginated<ClientRow>
  filters: { readonly search: string }
}

/** Contact, projects and locations are columns of one register, as in the Breeze app's client list. */
const COLUMNS: readonly TableColumn<ClientRow>[] = [
  {
    key: 'client',
    header: 'Client',
    render: (row) => (
      <div className="min-w-0">
        {/* A real link, so the row can be reached and opened from the keyboard too. */}
        <Link href={routeTo.client(row.id)} className="block truncate font-bold text-white hover:text-brand">
          {row.name}
        </Link>
        <p className="truncate text-[13px] text-[var(--text-3)]">{row.primarySite ?? 'No site on file'}</p>
      </div>
    ),
  },
  {
    key: 'contact',
    header: 'Contact',
    render: (row) => (
      <div className="min-w-0">
        <p className="truncate text-white">{row.contactName ?? row.name}</p>
        <p className="truncate text-[13px] text-[var(--text-3)]">{row.contactEmail ?? 'N/A'}</p>
        {row.contactPhone && <p className="truncate text-[13px] text-[var(--text-3)]">{row.contactPhone}</p>}
      </div>
    ),
  },
  {
    key: 'projects',
    header: 'Projects',
    align: 'right',
    render: (row) => <span className="tabular-nums">{row.projectCount}</span>,
  },
  {
    key: 'locations',
    header: 'Locations',
    align: 'right',
    render: (row) => <span className="tabular-nums">{row.siteCount}</span>,
  },
  {
    key: 'since',
    header: 'Client since',
    align: 'right',
    render: (row) => (
      <span className="whitespace-nowrap">{row.createdAt ? formatDate(row.createdAt) : '—'}</span>
    ),
  },
]

/**
 * The client register.
 *
 * A client is who the work is for. What they have on is their projects, so the
 * count is the column that matters — a name on its own tells you nothing you
 * did not already know. Laid out as the Breeze app's list: one card, a toolbar
 * with search and a count, and a row per client that opens its record.
 */
export default function Clients({ clients, filters }: ClientsProps) {
  const { can: permitted } = usePermissions()
  const [search, setSearch] = useState(filters.search)
  const rows = clients.data
  const total = clients.meta.total

  const apply = (changes: Record<string, string>) => {
    const query = new URLSearchParams(window.location.search)

    for (const [key, value] of Object.entries(changes)) {
      if (value === '') query.delete(key)
      else query.set(key, value)
    }

    // A new search starts at page one; paging passes its own through.
    query.delete('page')
    if (changes['page'] !== undefined) query.set('page', changes['page'])

    router.get(`${ROUTES.clients}?${query.toString()}`, undefined, {
      preserveState: true,
      replace: true,
    })
  }

  return (
    <PageTransition>
      <Head title="Clients" />

      <PageHeader
        title="Clients"
        subtitle="The companies you build for, with their contacts, projects and locations."
        actions={
          permitted('clients.create') ? (
            <ButtonLink href={ROUTES.clientCreate} leftIcon={Plus}>
              Add client
            </ButtonLink>
          ) : undefined
        }
      />

      <Card padding="none" className="overflow-hidden">
        {/* Toolbar: search on the left, the count on the right. */}
        <div className="flex flex-wrap items-center gap-3 border-b border-hairline px-4 py-3">
          <ToolbarSearch
            value={search}
            onChange={setSearch}
            onSubmit={(value) => apply({ search: value })}
            applied={filters.search !== ''}
            placeholder="Search clients"
          />

          <span className="ml-auto text-[13px] text-[var(--text-3)]">
            {total} client{total === 1 ? '' : 's'}
          </span>
        </div>

        {rows.length === 0 ? (
          <EmptyState
            icon={Search}
            title={filters.search ? 'No client matches that' : 'No clients yet'}
            description={
              filters.search
                ? 'Clear the search to see everyone on the register.'
                : 'Add the client the work is for, then open a project under them.'
            }
            {...(filters.search || !permitted('clients.create')
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
            columns={COLUMNS}
            rows={rows}
            getRowId={(row) => row.id}
            caption="Clients"
            onRowClick={(row) => router.visit(routeTo.client(row.id))}
          />
        )}
      </Card>

      <Pagination
        withLabels
        className="mt-5"
        page={clients.meta.current_page}
        pageCount={clients.meta.last_page}
        onPageChange={(page) => apply({ page: String(page) })}
        summary={total === 0 ? 'No clients to display' : `Showing ${rows.length} of ${total} clients`}
      />
    </PageTransition>
  )
}

Clients.layout = appLayout
