import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { Eye, FolderKanban, MapPin, Plus } from 'lucide-react'
import {
  ButtonLink,
  Card,
  EmptyState,
  Pagination,
  SearchBox,
  Table,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { Paginated, TableColumn } from '@/types'

interface ClientRow {
  readonly id: number
  readonly name: string
  readonly projectCount: number
  readonly siteCount: number
  readonly primarySite: string | null
}

export interface ClientsProps {
  clients: Paginated<ClientRow>
  filters: { readonly search: string }
}

/**
 * The client register.
 *
 * A client is who the work is for. What they have on is their projects, so the
 * count is the column that matters — a name on its own tells you nothing you
 * did not already know.
 */
export default function Clients({ clients, filters }: ClientsProps) {
  const [search, setSearch] = useState(filters.search)
  const rows = clients.data

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

  const columns: TableColumn<ClientRow>[] = [
    {
      key: 'name',
      header: 'Client',
      render: (row) => (
        <Link href={routeTo.client(row.id)} className="group block min-w-0">
          <span className="block truncate font-semibold text-white transition-colors group-hover:text-brand">
            {row.name}
          </span>
          <span className="flex items-center gap-1.5 text-sm text-white/60">
            <MapPin size={13} aria-hidden className="shrink-0" />
            {row.primarySite ?? 'No location recorded'}
          </span>
        </Link>
      ),
    },
    {
      key: 'projects',
      header: 'Projects',
      align: 'right',
      width: 'w-28',
      render: (row) => <span className="tabular-nums text-white/90">{row.projectCount}</span>,
    },
    {
      key: 'sites',
      header: 'Locations',
      align: 'right',
      width: 'w-24',
      render: (row) => <span className="tabular-nums text-white/90">{row.siteCount}</span>,
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      width: 'w-24',
      render: (row) => (
        <ButtonLink
          href={routeTo.client(row.id)}
          variant="ghost"
          size="sm"
          leftIcon={Eye}
          aria-label={`View ${row.name}`}
        >
          View
        </ButtonLink>
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title="Clients" />

      <PageHeader
        title="Clients"
        subtitle="Who the work is for. Each client's projects are what the work is."
        breadcrumbs={[{ label: 'Clients' }]}
        actions={
          <ButtonLink href={ROUTES.clientCreate} leftIcon={Plus}>
            Add Client
          </ButtonLink>
        }
      />

      <Card padding="md" className="mb-4">
        <SearchBox
          value={search}
          onValueChange={setSearch}
          onSearch={(value) => apply({ search: value })}
          placeholder="Search clients…"
          aria-label="Search clients"
          containerClassName="sm:max-w-xs"
        />
      </Card>

      <Card padding="lg">
        {rows.length === 0 ? (
          <EmptyState
            icon={FolderKanban}
            title={filters.search ? 'No client matches that' : 'No clients yet'}
            description={
              filters.search
                ? 'Clear the search to see everyone on the register.'
                : 'Add the client the work is for, then open a project under them.'
            }
            {...(filters.search
              ? {}
              : {
                  actions: (
                    <ButtonLink href={ROUTES.clientCreate} leftIcon={Plus}>
                      Add Client
                    </ButtonLink>
                  ),
                })}
          />
        ) : (
          <Table
            columns={columns}
            rows={rows}
            getRowId={(row) => row.id}
            variant="lined"
            caption="Clients on the register"
          />
        )}
      </Card>

      <Pagination
        withLabels
        className="mt-6"
        page={clients.meta.current_page}
        pageCount={clients.meta.last_page}
        onPageChange={(page) => apply({ page: String(page) })}
        summary={
          clients.meta.total === 0
            ? 'No clients to display'
            : `Showing ${rows.length} of ${clients.meta.total} clients`
        }
      />
    </PageTransition>
  )
}

Clients.layout = appLayout
