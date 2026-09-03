import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { Eye, HardHat, Plus } from 'lucide-react'
import {
  ButtonLink,
  Card,
  EmptyState,
  Pagination,
  SearchBox,
  StatusChip,
  Table,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { Paginated, TableColumn } from '@/types'
import { formatDate, formatHours } from '@/utils'

interface ForemanRow {
  readonly id: number
  readonly name: string
  readonly initials: string
  /** Open work only — what they are carrying now, not what they ever carried. */
  readonly openTasks: number
  readonly openJobs: number
  readonly openHours: number
  readonly phone: string | null
  readonly email: string | null
  readonly licenceNumber: string | null
  readonly joinedOn: string | null
}

export interface ForemenProps {
  foremen: Paginated<ForemanRow>
  filters: { readonly search: string }
  /** False for anyone who cannot staff work — the list is still readable. */
  canManage: boolean
}

/** The task list, narrowed to one foreman — what a row's numbers describe. */
const tasksFor = (name: string) => `${ROUTES.tasks}?foreman=${encodeURIComponent(name)}`

/**
 * The foremen a task can be handed to.
 *
 * The numbers are what the list is for: who is already carrying work, and who
 * has room. All of them count open work only — a foreman who finished forty
 * tasks last year is as free as one who has never had any — and the name opens
 * the task list filtered to them, so a number is a way in rather than trivia.
 */
export default function Foremen({ foremen, filters, canManage }: ForemenProps) {
  const [search, setSearch] = useState(filters.search)
  const rows = foremen.data

  const apply = (changes: Record<string, string>) => {
    const query = new URLSearchParams(window.location.search)

    for (const [key, value] of Object.entries(changes)) {
      if (value === '') query.delete(key)
      else query.set(key, value)
    }

    // Any change to the search puts you back on page one; paging passes its
    // own `page` through, so it is set after this and not cleared.
    query.delete('page')
    if (changes['page'] !== undefined) query.set('page', changes['page'])

    router.get(`${ROUTES.foremen}?${query.toString()}`, undefined, {
      preserveState: true,
      replace: true,
    })
  }

  const columns: TableColumn<ForemanRow>[] = [
    {
      key: 'name',
      header: 'Foreman',
      render: (row) => (
        <Link href={tasksFor(row.name)} className="group flex items-center gap-3">
          <span
            className={
              row.openTasks > 0
                ? 'grid size-9 shrink-0 place-items-center rounded-full bg-brand/15 text-sm font-semibold text-brand ring-1 ring-brand/30'
                : 'grid size-9 shrink-0 place-items-center rounded-full bg-white/8 text-sm font-semibold text-white/70 ring-1 ring-hairline'
            }
          >
            {row.initials}
          </span>
          <span className="min-w-0">
            <span className="block truncate font-semibold text-white transition-colors group-hover:text-brand">
              {row.name}
            </span>
            {/* Identity, so it sits with the name rather than in a column. */}
            <span className="block truncate text-sm text-white/60">
              {row.licenceNumber ? `Licence ${row.licenceNumber}` : 'No licence recorded'}
            </span>
          </span>
        </Link>
      ),
    },
    {
      key: 'contact',
      header: 'Contact',
      // Recorded when they were added; shown here so it is not write-only.
      render: (row) =>
        row.phone === null && row.email === null ? (
          <span className="text-sm text-white/45">Not recorded</span>
        ) : (
          <span className="block min-w-0 text-sm">
            {row.phone && <span className="block text-white/90">{row.phone}</span>}
            {row.email && (
              <a
                href={`mailto:${row.email}`}
                className="block truncate text-white/70 transition-colors hover:text-brand"
              >
                {row.email}
              </a>
            )}
          </span>
        ),
    },
    {
      key: 'joined',
      header: 'Joined',
      render: (row) => (
        <span className="whitespace-nowrap text-sm text-white/80">
          {row.joinedOn ? formatDate(row.joinedOn) : '—'}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      // Free is the state worth spotting — it is who you hand work to.
      render: (row) => (
        <StatusChip
          hideDot
          tone={row.openTasks > 0 ? 'brand' : 'neutral'}
          label={row.openTasks > 0 ? 'On work' : 'Free'}
        />
      ),
    },
    {
      key: 'tasks',
      header: 'Open tasks',
      align: 'right',
      render: (row) => <span className="tabular-nums text-white/90">{row.openTasks}</span>,
    },
    {
      key: 'jobs',
      header: 'Jobs',
      align: 'right',
      render: (row) => <span className="tabular-nums text-white/90">{row.openJobs}</span>,
    },
    {
      key: 'hours',
      header: 'Hours',
      align: 'right',
      render: (row) => (
        <span className="whitespace-nowrap tabular-nums text-white/90">
          {row.openHours > 0 ? formatHours(row.openHours) : '—'}
        </span>
      ),
    },
    {
      key: 'actions',
      header: 'View',
      align: 'right',
      width: 'w-24',
      render: (row) => (
        <ButtonLink
          href={routeTo.foreman(row.id)}
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
      <Head title="Foremen" />

      <PageHeader
        title="Foremen"
        subtitle="Who work can be handed to, and how much each is already carrying."
        breadcrumbs={[{ label: 'Jobs', href: ROUTES.jobs }, { label: 'Foremen' }]}
        actions={
          canManage ? (
            <ButtonLink href={ROUTES.foremanCreate} leftIcon={Plus}>
              Add foreman
            </ButtonLink>
          ) : undefined
        }
      />

      <Card padding="md" className="mb-4">
        <SearchBox
          value={search}
          onValueChange={setSearch}
          onSearch={(value) => apply({ search: value })}
          placeholder="Search foremen…"
          aria-label="Search foremen"
          containerClassName="sm:max-w-xs"
        />
      </Card>

      <Card padding="lg">
        {rows.length === 0 ? (
          <EmptyState
            icon={HardHat}
            title={filters.search ? 'No foreman matches that' : 'No foremen yet'}
            description={
              filters.search
                ? 'Clear the search to see everyone on the list.'
                : 'Add the people who run work on site, then hand them tasks.'
            }
            {...(!filters.search && canManage
              ? {
                  actions: (
                    <ButtonLink href={ROUTES.foremanCreate} leftIcon={Plus}>
                      Add foreman
                    </ButtonLink>
                  ),
                }
              : {})}
          />
        ) : (
          <Table
            columns={columns}
            rows={rows}
            getRowId={(row) => row.id}
            variant="lined"
            caption="Foremen on record"
          />
        )}
      </Card>

      <Pagination
        withLabels
        className="mt-6"
        page={foremen.meta.current_page}
        pageCount={foremen.meta.last_page}
        onPageChange={(page) => apply({ page: String(page) })}
        summary={
          foremen.meta.total === 0
            ? 'No foremen to display'
            : `Showing ${rows.length} of ${foremen.meta.total} foremen`
        }
      />
    </PageTransition>
  )
}

Foremen.layout = appLayout
