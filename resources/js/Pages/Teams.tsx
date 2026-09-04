import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { Eye, HardHat, UserPlus, Users } from 'lucide-react'
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

interface MemberRow {
  readonly id: number
  readonly name: string
  readonly initials: string
  /** What they do on the crew: `supervisor` or `foreman`. */
  readonly role: string
  readonly roleLabel: string
  /** Open work only — what they are carrying now, not what they ever carried. */
  readonly openTasks: number
  readonly openJobs: number
  readonly openHours: number
  readonly phone: string | null
  readonly email: string | null
  readonly licenceNumber: string | null
  readonly joinedOn: string | null
}

interface TeamGroup {
  readonly id: number
  readonly name: string
  readonly members: readonly MemberRow[]
}

export interface TeamsProps {
  teams: Paginated<TeamGroup>
  /**
   * Everyone on no crew. Not a team, and not hidden either: people added before
   * teams existed have none, and so does anyone hired before their crew is
   * decided.
   */
  unassigned: readonly MemberRow[]
  filters: { readonly search: string }
  /** False for anyone who cannot staff work — the register is still readable. */
  canManage: boolean
}

/** The task list, narrowed to one member — what a row's numbers describe. */
const tasksFor = (name: string) => `${ROUTES.tasks}?foreman=${encodeURIComponent(name)}`

/**
 * The crew register, read the way work is staffed: by team.
 *
 * A flat list of names answered "who is free" but never "who is free on the
 * crew already on this site". The numbers are still what each row is for — all
 * of them count open work only, and a name opens the task list filtered to that
 * person, so a number is a way in rather than trivia.
 */
export default function Teams({ teams, unassigned, filters, canManage }: TeamsProps) {
  const [search, setSearch] = useState(filters.search)
  const groups = teams.data

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

    router.get(`${ROUTES.teams}?${query.toString()}`, undefined, {
      preserveState: true,
      replace: true,
    })
  }

  const columns: TableColumn<MemberRow>[] = [
    {
      key: 'name',
      header: 'Member',
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
      key: 'role',
      header: 'Role',
      /* Toned, not just written: a supervisor reads differently from a foreman
         at a glance, which is the point of showing it in the crew's own list. */
      render: (row) => (
        <StatusChip
          hideDot
          tone={row.role === 'supervisor' ? 'info' : 'neutral'}
          label={row.roleLabel}
        />
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

  const nothingAtAll = groups.length === 0 && unassigned.length === 0

  return (
    <PageTransition>
      <Head title="Teams" />

      <PageHeader
        title="Teams"
        subtitle="The crews work is handed to, and how much each member is already carrying."
        breadcrumbs={[{ label: 'Jobs', href: ROUTES.jobs }, { label: 'Teams' }]}
        actions={
          canManage ? (
            <>
              <ButtonLink href={ROUTES.teamCreate} variant="secondary" leftIcon={Users}>
                Add team
              </ButtonLink>
              <ButtonLink href={ROUTES.foremanCreate} leftIcon={UserPlus}>
                Add member
              </ButtonLink>
            </>
          ) : undefined
        }
      />

      <Card padding="md" className="mb-4">
        <SearchBox
          value={search}
          onValueChange={setSearch}
          onSearch={(value) => apply({ search: value })}
          placeholder="Search teams and members…"
          aria-label="Search teams and members"
          containerClassName="sm:max-w-xs"
        />
      </Card>

      {nothingAtAll ? (
        <Card accent="brand" padding="lg">
          <EmptyState
            icon={HardHat}
            title={filters.search ? 'Nothing matches that' : 'No teams yet'}
            description={
              filters.search
                ? 'Clear the search to see every crew on the register.'
                : 'Add a crew, then add the people who run its work.'
            }
            {...(!filters.search && canManage
              ? {
                  actions: (
                    <ButtonLink href={ROUTES.teamCreate} leftIcon={Users}>
                      Add team
                    </ButtonLink>
                  ),
                }
              : {})}
          />
        </Card>
      ) : (
        <div className="space-y-6">
          {groups.map((team) => (
            <TeamCard
              key={team.id}
              name={team.name}
              members={team.members}
              columns={columns}
              canManage={canManage}
            />
          ))}

          {unassigned.length > 0 && (
            <TeamCard
              name="Not on a team"
              /* Said plainly rather than left as a gap: these are people on the
                 register whose crew nobody has decided yet. */
              description="Everyone on the register who has not been put on a crew."
              members={unassigned}
              columns={columns}
              canManage={canManage}
            />
          )}
        </div>
      )}

      <Pagination
        withLabels
        className="mt-6"
        page={teams.meta.current_page}
        pageCount={teams.meta.last_page}
        onPageChange={(page) => apply({ page: String(page) })}
        summary={
          teams.meta.total === 0
            ? 'No teams to display'
            : `Showing ${groups.length} of ${teams.meta.total} teams`
        }
      />
    </PageTransition>
  )
}

interface TeamCardProps {
  name: string
  /** Overrides the member count, for a group that needs explaining. */
  description?: string
  members: readonly MemberRow[]
  columns: TableColumn<MemberRow>[]
  canManage: boolean
}

/** One crew and everyone on it. */
function TeamCard({ name, description, members, columns, canManage }: TeamCardProps) {
  return (
    <Card accent="success" padding="lg">
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="text-lg font-semibold text-white">{name}</h2>
          <p className="mt-1 text-sm text-white/70">
            {description ??
              `${members.length} ${members.length === 1 ? 'member' : 'members'}`}
          </p>
        </div>
      </div>

      {members.length === 0 ? (
        /* A crew with nobody on it is a real state — it was just created — and
           the way out of it is the button that put it here. */
        <p className="text-md text-white/75">
          Nobody is on this crew yet.
          {canManage && (
            <>
              {' '}
              <Link href={ROUTES.foremanCreate} className="text-brand hover:underline">
                Add a member
              </Link>
              .
            </>
          )}
        </p>
      ) : (
        <Table
          columns={columns}
          rows={members}
          getRowId={(row) => row.id}
          variant="lined"
          caption={`${name} members`}
        />
      )}
    </Card>
  )
}

Teams.layout = appLayout
