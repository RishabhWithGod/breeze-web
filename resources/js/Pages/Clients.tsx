import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { Eye, FolderKanban, Mail, MapPin, Phone, Plus, User } from 'lucide-react'
import {
  Badge,
  ButtonLink,
  Card,
  EmptyState,
  Pagination,
  SearchBox,
  Table,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { usePermissions } from '@/hooks'
import type { Paginated, TableColumn } from '@/types'

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
  const { can: permitted } = usePermissions()
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
      key: 'contact',
      header: 'Primary Contact',
      width: 'w-56',
      render: (row) => (
        <div className="space-y-0.5 text-xs text-white/60">
          <span className="flex items-center gap-1.5 truncate">
            <User size={12} aria-hidden className="shrink-0" />
            <span className="truncate">{row.contactName ?? row.name}</span>
          </span>
          <span className="flex items-center gap-1.5 truncate">
            <Mail size={12} aria-hidden className="shrink-0" />
            <span className="truncate">{row.contactEmail ?? 'N/A'}</span>
          </span>
          <span className="flex items-center gap-1.5 truncate">
            <Phone size={12} aria-hidden className="shrink-0" />
            <span className="truncate">{row.contactPhone ?? 'N/A'}</span>
          </span>
        </div>
      ),
    },
    {
      key: 'projects',
      header: 'Projects',
      align: 'right',
      width: 'w-24',
      render: (row) => (
        <span className="text-sm tabular-nums text-white/90">{row.projectCount}</span>
      ),
    },
    {
      key: 'sites',
      header: 'Locations',
      align: 'right',
      width: 'w-24',
      render: (row) => (
        <span className="text-sm tabular-nums text-white/90">{row.siteCount}</span>
      ),
    },
    {
      /*
       * Not a real feature yet — there is nothing behind this column to
       * report. Shown anyway, but plainly marked so it reads as "coming
       * later" rather than as data nobody trusts.
       */
      key: 'commodityLists',
      header: 'Commodity Lists',
      width: 'w-44',
      render: () => (
        <span title="Commodity lists aren't tracked yet — coming soon">
          <Badge tone="neutral" size="sm">
            Not available yet
          </Badge>
        </span>
      ),
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
        subtitle="Manage your clients and their associated projects, estimates, and activity — All in one place."
        breadcrumbs={[{ label: 'Clients' }]}
        actions={
          permitted('clients.create') ? (
            <ButtonLink href={ROUTES.clientCreate} leftIcon={Plus}>
              Add Client
            </ButtonLink>
          ) : undefined
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
            {...(filters.search || !permitted('clients.create')
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
            dense
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
