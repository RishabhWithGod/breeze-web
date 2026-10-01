import { useState } from 'react'
import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import type { LucideIcon } from 'lucide-react'
import {
  Check,
  Eye,
  GraduationCap,
  HardHat,
  Pencil,
  ShieldCheck,
  Trash2,
  UserCheck,
  UserPlus,
  UserRound,
  Users,
  Wrench,
  X,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  EmptyState,
  FilterTabs,
  Modal,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { usePermissions } from '@/hooks'
import type { Paginated, SharedPageProps, TableColumn } from '@/types'
import { formatDate } from '@/utils'

interface MemberRow {
  readonly id: number
  readonly name: string
  readonly initials: string
  /** What they do on the crew: `foreman`, `journeyman` or `apprentice`. */
  readonly role: string
  readonly roleLabel: string
  /** Open work only — what they are carrying now, not what they ever carried. */
  readonly openTasks: number
  readonly openJobs: number
  readonly openHours: number
  /** The jobs their open tasks are on. */
  readonly jobs: readonly string[]
  /** Checked in at a site right now. */
  readonly onSite: boolean
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

interface TeamOption {
  readonly id: number
  readonly name: string
}

interface TechnicianRow {
  readonly id: number
  readonly name: string
  readonly email: string
  readonly phone: string | null
  readonly status: 'pending_approval' | 'active' | 'rejected' | 'inactive'
  /** 'Foreman' is only ever the default every mobile signup starts as. */
  readonly role: string
  readonly approvedAt: string | null
  readonly approvedBy: string | null
  readonly teamMember: { readonly id: number; readonly teamId: number | null; readonly teamName: string | null } | null
  readonly createdAt: string | null
}

export interface ManagerRow {
  readonly id: number
  readonly name: string
  readonly email: string
  readonly phone: string | null
  readonly role: string
  readonly isOwner: boolean
  readonly isYou: boolean
  readonly canEdit: boolean
  readonly canEditEmail: boolean
  readonly addedAt: string | null
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
  /** Technicians who signed up from the mobile app, awaiting a decision. */
  pendingTechnicians: readonly TechnicianRow[]
  activeTechnicians: readonly TechnicianRow[]
  rejectedTechnicians: readonly TechnicianRow[]
  /** False for anyone who cannot approve/reject a technician application. */
  canApproveTechnicians: boolean
  /** True for a manager of a company — who gets the Managers tab. */
  canSeeManagers: boolean
  /** Everyone who manages the company, its owner first. */
  managers: readonly ManagerRow[]
  /** Every team, unpaginated — for the "assign a team" picker. */
  teamOptions: readonly TeamOption[]
  /** 'Foreman' / 'Journeyman' / 'Apprentice' — what a mobile signup can be corrected to. */
  technicianRoleOptions: readonly string[]
}

/** The people who manage this company, its owner first. */
function ManagersList({ managers, canAdd }: { managers: readonly ManagerRow[]; canAdd: boolean }) {
  return (
    <Card padding="none" className="overflow-hidden">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-4">
        <div>
          <h2 className="text-lg font-semibold text-white">Managers</h2>
          <p className="text-sm text-white/70">
            Everyone here sees all of this company&apos;s clients, projects, jobs, crews and time.
          </p>
        </div>
        {canAdd && (
          <ButtonLink href={ROUTES.foremanCreate} leftIcon={UserPlus}>
            Add manager
          </ButtonLink>
        )}
      </header>

      <ul className="divide-y divide-hairline">
        {managers.map((manager) => (
          <li key={manager.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div className="flex min-w-0 items-center gap-4">
              <span className="grid size-10 shrink-0 place-items-center rounded-full bg-brand/20 text-sm font-bold text-white">
                {manager.name
                  .split(' ')
                  .slice(0, 2)
                  .map((word) => word.charAt(0).toUpperCase())
                  .join('')}
              </span>
              <div className="min-w-0">
                <p className="text-md font-semibold text-white">
                  {manager.name}
                  {manager.isYou && <span className="ml-2 text-xs font-normal text-white/60">(you)</span>}
                </p>
                <p className="truncate text-sm text-white/70">
                  {manager.email}
                  {manager.phone ? ` · ${manager.phone}` : ''}
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2">
              {manager.addedAt && (
                <span className="hidden text-xs text-white/60 sm:inline">Added {formatDate(manager.addedAt)}</span>
              )}
              <StatusChip
                pill
                hideDot
                tone={manager.isOwner ? 'brand' : 'neutral'}
                label={manager.isOwner ? 'Owner' : manager.role}
              />
              {manager.canEdit && (
                <ButtonLink href={routeTo.managerEdit(manager.id)} variant="secondary" size="sm">
                  Edit
                </ButtonLink>
              )}
            </div>
          </li>
        ))}
      </ul>
    </Card>
  )
}

/** The task list, narrowed to one member — what a row's numbers describe. */
const tasksFor = (name: string) => `${ROUTES.tasks}?foreman=${encodeURIComponent(name)}`

/** The stored value is already the label — kept as a function so every call site reads the same way. */
const roleLabel = (role: string) => role

/** A colour and icon per role, so a crew reads at a glance. */
const ROLE_TONE: Record<string, { chip: string; icon: LucideIcon }> = {
  Foreman: { chip: 'bg-status-success/12 text-status-success ring-status-success/40', icon: HardHat },
  Journeyman: { chip: 'bg-status-warning/12 text-status-warning ring-status-warning/40', icon: Wrench },
  Apprentice: { chip: 'bg-status-purple/12 text-status-purple ring-status-purple/40', icon: GraduationCap },
  'Project Manager': { chip: 'bg-brand/12 text-brand ring-brand/40', icon: UserRound },
}

/**
 * The crew register, read the way work is staffed: by team — plus
 * technicians who signed up from the mobile app, on this same page rather
 * than a separate one. Approving someone and staffing a crew is one job for
 * a manager, not two screens.
 */
export default function Teams({
  teams,
  unassigned,
  filters,
  canManage,
  pendingTechnicians,
  activeTechnicians,
  rejectedTechnicians,
  canApproveTechnicians,
  canSeeManagers,
  managers,
  teamOptions,
  technicianRoleOptions,
}: TeamsProps) {
  const { can } = usePermissions()
  const [search, setSearch] = useState(filters.search)
  // Which tab is open lives in the URL, so a link or a reload lands on the same one.
  const [tab, setTab] = useState<'teams' | 'managers'>(() =>
    typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab') === 'managers'
      ? 'managers'
      : 'teams',
  )
  const openTab = (next: 'teams' | 'managers') => {
    setTab(next)
    const params = new URLSearchParams(window.location.search)
    if (next === 'managers') params.set('tab', next)
    else params.delete('tab')
    const query = params.toString()
    window.history.replaceState(window.history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}`)
  }
  const showingManagers = canSeeManagers && tab === 'managers'
  const { flash, errors } = usePage<SharedPageProps>().props
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
                ? 'grid size-9 shrink-0 place-items-center rounded-full bg-brand/15 text-xs font-semibold text-brand ring-1 ring-brand/30'
                : 'grid size-9 shrink-0 place-items-center rounded-full bg-white/8 text-xs font-semibold text-white/75 ring-1 ring-hairline'
            }
          >
            {row.initials}
          </span>
          <span className="truncate font-semibold text-white transition-colors group-hover:text-brand">{row.name}</span>
        </Link>
      ),
    },
    {
      key: 'role',
      header: 'Role',
      render: (row) => {
        const tone = ROLE_TONE[row.roleLabel] ?? ROLE_TONE['Project Manager']!

        return (
          <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${tone.chip}`}
          >
            <tone.icon size={13} aria-hidden />
            {row.roleLabel}
          </span>
        )
      },
    },
    {
      key: 'job',
      header: 'Assigned Project or Job',
      render: (row) =>
        row.jobs.length === 0 ? (
          <span className="text-white/50">Nothing assigned</span>
        ) : (
          <span className="text-white" title={row.jobs.join(', ')}>
            {row.jobs[0]}
            {row.jobs.length > 1 && <span className="text-white/65"> +{row.jobs.length - 1} more</span>}
          </span>
        ),
    },
    {
      key: 'tasks',
      header: 'Open Tasks',
      align: 'center',
      render: (row) => <span className="tabular-nums text-white">{row.openTasks}</span>,
    },
    {
      key: 'availability',
      header: 'Availability',
      // One answer, not two: on a site now, carrying work, or free to be handed some.
      render: (row) => {
        const state = row.onSite
          ? { label: 'On site', dot: 'bg-brand', chip: 'bg-brand/12 text-brand ring-brand/40' }
          : row.openTasks > 0
            ? {
                label: 'On work',
                dot: 'bg-status-warning',
                chip: 'bg-status-warning/12 text-status-warning ring-status-warning/40',
              }
            : {
                label: 'Available',
                dot: 'bg-status-success',
                chip: 'bg-status-success/12 text-status-success ring-status-success/40',
              }

        return (
          <span
            className={`inline-flex items-center gap-2 whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold ring-1 ${state.chip}`}
          >
            <span className={`size-2 rounded-full ${state.dot}`} aria-hidden />
            {state.label}
          </span>
        )
      },
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
        breadcrumbs={[{ label: 'Clients', href: ROUTES.clients }, { label: 'Teams' }]}
        actions={
          canManage || can('admin.manage_roles') ? (
            <>
              {can('admin.manage_roles') && (
                <ButtonLink href={ROUTES.rolesPermissions} variant="secondary" leftIcon={ShieldCheck}>
                  Roles &amp; Permissions
                </ButtonLink>
              )}
              {canManage && (
                <>
                  <ButtonLink href={ROUTES.teamCreate} variant="secondary" leftIcon={Users}>
                    Add team
                  </ButtonLink>
                  <ButtonLink href={ROUTES.foremanCreate} leftIcon={UserPlus}>
                    Add member
                  </ButtonLink>
                </>
              )}
            </>
          ) : undefined
        }
      />

      {flash.success && (
        <Alert key={flash.success} tone="success" className="mb-6">
          {flash.success}
        </Alert>
      )}
      {flash.warning && (
        <Alert key={flash.warning} tone="warning" className="mb-6">
          {flash.warning}
        </Alert>
      )}

      {errors['team_id'] && (
        <Alert key={errors['team_id']} tone="danger" className="mb-6">
          {errors['team_id']}
        </Alert>
      )}

      {canSeeManagers && (
        <FilterTabs
          className="mb-5"
          value={showingManagers ? 'managers' : 'teams'}
          onChange={openTab}
          options={[
            { label: 'Teams', value: 'teams' },
            { label: 'Managers', value: 'managers' },
          ]}
          counts={{ managers: managers.length }}
        />
      )}

      {showingManagers ? (
        <ManagersList managers={managers} canAdd={canManage} />
      ) : (
        <>
          {/* First thing on the page, and only when someone is waiting — no request, no card.
          Rejected applications are further down. */}
          {pendingTechnicians.length > 0 && (
            <TechnicianApprovalSection
              pendingTechnicians={pendingTechnicians}
              canApprove={canApproveTechnicians}
              teamOptions={teamOptions}
              roleOptions={technicianRoleOptions}
            />
          )}

          <Card padding="md" className="mb-5">
            <SearchBox
              value={search}
              onValueChange={setSearch}
              onSearch={(value) => apply({ search: value })}
              placeholder="Search teams and members..."
              aria-label="Search teams and members"
              containerClassName="sm:max-w-sm"
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
              {groups.map((team, index) => (
                <TeamCard
                  key={team.id}
                  teamId={team.id}
                  name={team.name}
                  members={team.members}
                  columns={columns}
                  canManage={canManage}
                  index={index}
                />
              ))}

              {unassigned.length > 0 && (
                <TeamCard
                  /* Last in the list, so it carries on the same colour cycle. */
                  index={groups.length}
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

          <RolePermissions />

          <Pagination
            withLabels
            className="mt-6"
            page={teams.meta.current_page}
            pageCount={teams.meta.last_page}
            onPageChange={(page) => apply({ page: String(page) })}
            summary={
              teams.meta.total === 0 ? 'No teams to display' : `Showing ${groups.length} of ${teams.meta.total} teams`
            }
          />

          {(activeTechnicians.length > 0 || rejectedTechnicians.length > 0) && (
            <TechnicianRegisterSection
              activeTechnicians={activeTechnicians}
              rejectedTechnicians={rejectedTechnicians}
              canApprove={canApproveTechnicians}
              teamOptions={teamOptions}
              roleOptions={technicianRoleOptions}
            />
          )}
        </>
      )}
    </PageTransition>
  )
}

/*
 * What each role can do, from the rules the app actually enforces — not a wish
 * list. Planning work is for project managers and foremen; deciding time and
 * billing is for project managers; the crew's time is visible to all four.
 */
const ROLE_SUMMARY: readonly {
  role: string
  icon: LucideIcon
  colour: string
  ring: string
  points: readonly string[]
}[] = [
  {
    role: 'Project Manager',
    icon: UserRound,
    colour: 'text-brand',
    ring: 'bg-brand/15 ring-brand/40',
    points: [
      'Plan and assign work on their own jobs',
      "Approve the crew's time",
      'Raise and send invoices',
      'See job costs and reports',
    ],
  },
  {
    role: 'Foreman',
    icon: HardHat,
    colour: 'text-status-success',
    ring: 'bg-status-success/15 ring-status-success/40',
    points: ['Plan tasks and schedules', 'Staff tasks from their crew', "See the crew's time"],
  },
  {
    role: 'Journeyman',
    icon: Wrench,
    colour: 'text-status-warning',
    ring: 'bg-status-warning/15 ring-status-warning/40',
    points: ['Work the tasks handed to them', "See the crew's time"],
  },
  {
    role: 'Apprentice',
    icon: GraduationCap,
    colour: 'text-status-purple',
    ring: 'bg-status-purple/15 ring-status-purple/40',
    points: ['Work the tasks handed to them', "See the crew's time"],
  },
]

function RolePermissions() {
  return (
    <Card padding="md" className="mt-6">
      <div className="flex items-center gap-4">
        <span className="grid size-12 shrink-0 place-items-center rounded-panel bg-brand/15 text-brand ring-1 ring-brand/30">
          <ShieldCheck size={24} aria-hidden />
        </span>
        <div>
          <h2 className="text-lg font-semibold text-white">Role Permissions Summary</h2>
          <p className="text-sm text-white/75">What each role can do in Breeze.</p>
        </div>
      </div>

      <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {ROLE_SUMMARY.map(({ role, icon: Icon, colour, ring, points }) => (
          <div key={role} className="rounded-panel border border-hairline bg-white/4 p-4">
            <div className="flex items-center gap-3">
              <span className={`grid size-11 shrink-0 place-items-center rounded-full ring-1 ${ring}`}>
                <Icon size={21} aria-hidden className={colour} />
              </span>
              <h3 className={`text-md font-semibold ${colour}`}>{role}</h3>
            </div>
            <ul className="mt-4 space-y-2">
              {points.map((point) => (
                <li key={point} className="flex items-start gap-2.5 text-sm text-white/90">
                  <Check size={15} aria-hidden className="mt-0.5 shrink-0 text-brand" />
                  {point}
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </Card>
  )
}

interface TeamCardProps {
  /** Set for a real crew; absent for the "Not on a team" group, which is not one to rename or delete. */
  teamId?: number
  name: string
  /** Overrides the member count, for a group that needs explaining. */
  description?: string
  members: readonly MemberRow[]
  columns: TableColumn<MemberRow>[]
  canManage: boolean
  /** Position in the list, which is what picks the card's accent colour. */
  index: number
}

/** One crew and everyone on it. */
function TeamCard({ teamId, name, description, members, columns, canManage, index }: TeamCardProps) {
  const [renaming, setRenaming] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [busy, setBusy] = useState(false)

  // Only a crew with nobody on it can be deleted; a crew can always be renamed.
  const canEdit = canManage && teamId !== undefined
  const canDelete = canEdit && members.length === 0

  const remove = () => {
    if (teamId === undefined) return
    router.delete(routeTo.team(teamId), {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => {
        setBusy(false)
        setDeleting(false)
      },
    })
  }

  return (
    /* A colour per crew, cycling down the list the way every other list on the
       screen does — one repeated accent makes a page of crews read as one. */
    <Card accent="auto" index={index} padding="lg">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-4">
          <span className="grid size-12 shrink-0 place-items-center rounded-panel bg-linear-to-br from-brand/35 to-blue-500/25 text-white ring-1 ring-brand/30">
            <Users size={22} aria-hidden />
          </span>
          <div className="min-w-0">
            <h2 className="truncate text-xl font-semibold text-white">{name}</h2>
            <p className="text-sm text-white/70">
              {description ?? `${members.length} ${members.length === 1 ? 'member' : 'members'}`}
            </p>
          </div>
        </div>

        {/* Status and actions together, in the corner — not floating between the name and the buttons. */}
        <div className="ml-auto flex flex-wrap items-center justify-end gap-3">
          {members.length > 0 && (
            <div className="flex flex-wrap items-center gap-2 text-xs font-semibold">
              <span className="rounded-full bg-status-warning/12 px-3 py-1 text-status-warning ring-1 ring-status-warning/30">
                {members.filter((member) => member.openTasks > 0).length} on work
              </span>
              <span className="rounded-full bg-status-success/12 px-3 py-1 text-status-success ring-1 ring-status-success/30">
                {members.filter((member) => member.openTasks === 0).length} available
              </span>
            </div>
          )}

          {canEdit && (
            <div className="flex items-center gap-2">
              <Button variant="secondary" size="sm" leftIcon={Pencil} onClick={() => setRenaming(true)}>
                Rename
              </Button>
              {canDelete && (
                <Button variant="danger" size="sm" leftIcon={Trash2} onClick={() => setDeleting(true)}>
                  Delete team
                </Button>
              )}
            </div>
          )}
        </div>
      </div>

      {renaming && teamId !== undefined && (
        <RenameTeamModal teamId={teamId} currentName={name} onClose={() => setRenaming(false)} />
      )}
      <ConfirmDialog
        isOpen={deleting}
        title={`Delete “${name}”?`}
        description="This crew has nobody on it. Deleting it cannot be undone, but you can add a new crew any time."
        confirmLabel="Delete team"
        confirmVariant="danger"
        tone="danger"
        isBusy={busy}
        onConfirm={remove}
        onCancel={() => setDeleting(false)}
      />

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

/** Renaming a crew: just its name. */
function RenameTeamModal({
  teamId,
  currentName,
  onClose,
}: {
  teamId: number
  currentName: string
  onClose: () => void
}) {
  const { data, setData, put, processing, errors } = useForm({ name: currentName })

  const save = () => put(routeTo.team(teamId), { preserveScroll: true, onSuccess: onClose })

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Rename team"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            isLoading={processing}
            disabled={data.name.trim() === '' || data.name.trim() === currentName}
            onClick={save}
          >
            Save name
          </Button>
        </>
      }
    >
      <form
        onSubmit={(event) => {
          event.preventDefault()
          save()
        }}
      >
        <TextInput
          id={`rename-team-${teamId}`}
          label="Team name"
          autoFocus
          maxLength={120}
          value={data.name}
          onChange={(event) => setData('name', event.target.value)}
          {...(errors.name ? { error: errors.name } : {})}
        />
      </form>
    </Modal>
  )
}

interface TechnicianApprovalSectionProps {
  pendingTechnicians: readonly TechnicianRow[]
  canApprove: boolean
  teamOptions: readonly TeamOption[]
  roleOptions: readonly string[]
}

/** Technicians waiting on a manager's decision — the first thing on the page, because it is the most actionable. */
function TechnicianApprovalSection({
  pendingTechnicians,
  canApprove,
  teamOptions,
  roleOptions,
}: TechnicianApprovalSectionProps) {
  const [rejectTarget, setRejectTarget] = useState<TechnicianRow | null>(null)
  const [isBusy, setIsBusy] = useState(false)
  // Both required by the backend now — the blank option is a placeholder
  // that forces an explicit choice, not a submittable "leave it unset".
  const teamSelectOptions = [
    { value: '', label: 'Select a team' },
    ...teamOptions.map((team) => ({ value: String(team.id), label: team.name })),
  ]
  const roleSelectOptions = [
    { value: '', label: 'Select a role' },
    ...roleOptions.map((role) => ({ value: role, label: roleLabel(role) })),
  ]

  const approve = (technician: TechnicianRow, teamId: string, role: string) => {
    if (teamId === '' || role === '') return
    setIsBusy(true)
    router.post(
      routeTo.technicianApprove(technician.id),
      { team_id: Number(teamId), role },
      { preserveScroll: true, onFinish: () => setIsBusy(false) },
    )
  }

  const confirmReject = () => {
    if (!rejectTarget) return
    setIsBusy(true)
    router.post(
      routeTo.technicianReject(rejectTarget.id),
      {},
      {
        preserveScroll: true,
        onFinish: () => {
          setIsBusy(false)
          setRejectTarget(null)
        },
      },
    )
  }

  return (
    <Card accent="warning" padding="lg" className="mb-6">
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-lg font-semibold text-white">
            <UserCheck size={18} className="text-brand" />
            Technicians waiting for approval
          </h2>
          <p className="mt-1 text-sm text-white/70">
            Signed up from the mobile app. {pendingTechnicians.length}{' '}
            {pendingTechnicians.length === 1 ? 'technician' : 'technicians'} cannot use the app until approved.
          </p>
        </div>
      </div>

      <div className="space-y-3">
        {pendingTechnicians.map((technician) => (
          <PendingTechnicianRow
            key={technician.id}
            technician={technician}
            canApprove={canApprove}
            isBusy={isBusy}
            teamSelectOptions={teamSelectOptions}
            roleSelectOptions={roleSelectOptions}
            onApprove={approve}
            onReject={() => setRejectTarget(technician)}
          />
        ))}
      </div>

      <ConfirmDialog
        isOpen={rejectTarget !== null}
        title="Reject this application?"
        description={
          rejectTarget
            ? `${rejectTarget.name} will not be able to use the app. You can approve them later if this changes.`
            : undefined
        }
        confirmLabel="Reject"
        confirmVariant="danger"
        tone="danger"
        isBusy={isBusy}
        onConfirm={confirmReject}
        onCancel={() => setRejectTarget(null)}
      />
    </Card>
  )
}

interface PendingTechnicianRowProps {
  technician: TechnicianRow
  canApprove: boolean
  isBusy: boolean
  teamSelectOptions: readonly { value: string; label: string }[]
  roleSelectOptions: readonly { value: string; label: string }[]
  onApprove: (technician: TechnicianRow, teamId: string, role: string) => void
  onReject?: () => void
}

/** A row with an approve action — used for both pending applicants and, with `onReject` omitted, previously rejected ones being reconsidered. */
function PendingTechnicianRow({
  technician,
  canApprove,
  isBusy,
  teamSelectOptions,
  roleSelectOptions,
  onApprove,
  onReject,
}: PendingTechnicianRowProps) {
  // Left blank on purpose: team and role are both required to approve, so a
  // manager has to make an explicit choice rather than one being pre-filled
  // and accepted without a look.
  const [teamId, setTeamId] = useState('')
  const [role, setRole] = useState('')
  const canSubmit = teamId !== '' && role !== ''

  return (
    <div className="flex flex-wrap items-center gap-4 rounded-panel border border-hairline bg-white/4 p-4">
      <div className="min-w-0 flex-1">
        <p className="truncate font-semibold text-white">{technician.name}</p>
        <p className="truncate text-sm text-white/60">
          {technician.email}
          {technician.phone ? ` · ${technician.phone}` : ''}
        </p>
        <p className="mt-0.5 text-xs text-white/45">
          Signed up {technician.createdAt ? formatDate(technician.createdAt) : 'recently'}
        </p>
      </div>
      {canApprove ? (
        <>
          <SelectField
            id={`assign-role-${technician.id}`}
            aria-label={`Role for ${technician.name}`}
            className="w-40"
            value={role}
            onChange={(event) => setRole(event.target.value)}
            options={roleSelectOptions}
          />
          <SelectField
            id={`assign-team-${technician.id}`}
            aria-label={`Team for ${technician.name}`}
            className="w-48"
            value={teamId}
            onChange={(event) => setTeamId(event.target.value)}
            options={teamSelectOptions}
          />
          {onReject && (
            <Button size="sm" variant="danger" leftIcon={X} disabled={isBusy} onClick={onReject}>
              Reject
            </Button>
          )}
          <Button
            size="sm"
            leftIcon={Check}
            disabled={isBusy || !canSubmit}
            title={canSubmit ? undefined : 'Pick a role and a team before approving'}
            onClick={() => onApprove(technician, teamId, role)}
          >
            Approve
          </Button>
        </>
      ) : (
        <StatusChip hideDot tone="warning" label="Awaiting a manager" />
      )}
    </div>
  )
}

interface TechnicianRegisterSectionProps {
  activeTechnicians: readonly TechnicianRow[]
  /** Turned away earlier — a rejection is not final, so they can be approved from here. */
  rejectedTechnicians: readonly TechnicianRow[]
  canApprove: boolean
  teamOptions: readonly TeamOption[]
  roleOptions: readonly string[]
}

/** Approved (and rejected) technicians, below the crew list — a manager can still move someone between crews, or correct their role, from here. */
function TechnicianRegisterSection({
  activeTechnicians,
  rejectedTechnicians,
  canApprove,
  teamOptions,
  roleOptions,
}: TechnicianRegisterSectionProps) {
  const teamSelectOptions = [
    { value: '', label: 'No team yet' },
    ...teamOptions.map((team) => ({ value: String(team.id), label: team.name })),
  ]
  // A blank placeholder matters here too: a technician approved before this
  // page enforced valid roles can be sitting on a stale value (e.g. the old
  // free-text "Technician") that isn't 'Foreman'/'Journeyman'/'Apprentice' —
  // sending that stale value back on Assign fails validation with nothing
  // shown, so the row has to fall back to an explicit "pick one" state
  // instead.
  const roleSelectOptions = [
    { value: '', label: 'Select a role' },
    ...roleOptions.map((role) => ({ value: role, label: roleLabel(role) })),
  ]

  // A rejected applicant is approved through the same required-team-and-role
  // flow as a pending one, so the pickers here need a real placeholder rather
  // than the "No team yet" default the active-correction pickers use.
  const approvalTeamSelectOptions = [
    { value: '', label: 'Select a team' },
    ...teamOptions.map((team) => ({ value: String(team.id), label: team.name })),
  ]
  const approvalRoleSelectOptions = [
    { value: '', label: 'Select a role' },
    ...roleOptions.map((role) => ({ value: role, label: roleLabel(role) })),
  ]

  const [showRejected, setShowRejected] = useState(false)
  const [isApproveBusy, setIsApproveBusy] = useState(false)

  const approveRejected = (technician: TechnicianRow, teamId: string, role: string) => {
    if (teamId === '' || role === '') return
    setIsApproveBusy(true)
    router.post(
      routeTo.technicianApprove(technician.id),
      { team_id: Number(teamId), role },
      { preserveScroll: true, onFinish: () => setIsApproveBusy(false) },
    )
  }

  return (
    <div className="mt-6 space-y-6">
      {activeTechnicians.length > 0 && (
        <Card accent="neutral" padding="lg">
          <div className="mb-4">
            <h2 className="text-lg font-semibold text-white">Technicians</h2>
            <p className="mt-1 text-sm text-white/70">{activeTechnicians.length} approved from the mobile app.</p>
          </div>
          <div className="space-y-3">
            {activeTechnicians.map((technician) => (
              <ActiveTechnicianRow
                key={technician.id}
                technician={technician}
                canApprove={canApprove}
                teamSelectOptions={teamSelectOptions}
                roleSelectOptions={roleSelectOptions}
              />
            ))}
          </div>
        </Card>
      )}

      {rejectedTechnicians.length > 0 && (
        <Card accent="neutral" padding="lg">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-white">Rejected applications</h2>
              <p className="mt-1 text-sm text-white/70">
                A rejection is not final — pick a role and a team here to bring someone back in.
              </p>
            </div>
            <Button size="sm" variant="secondary" onClick={() => setShowRejected((value) => !value)}>
              {showRejected ? 'Hide' : 'View'} rejected applications ({rejectedTechnicians.length})
            </Button>
          </div>

          {showRejected && (
            <div className="space-y-3">
              {rejectedTechnicians.map((technician) =>
                canApprove ? (
                  <PendingTechnicianRow
                    key={technician.id}
                    technician={technician}
                    canApprove={canApprove}
                    isBusy={isApproveBusy}
                    teamSelectOptions={approvalTeamSelectOptions}
                    roleSelectOptions={approvalRoleSelectOptions}
                    onApprove={approveRejected}
                  />
                ) : (
                  <div
                    key={technician.id}
                    className="flex flex-wrap items-center gap-4 rounded-panel border border-hairline bg-white/4 p-4"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold text-white">{technician.name}</p>
                      <p className="truncate text-sm text-white/60">{technician.email}</p>
                    </div>
                    <StatusChip hideDot tone="danger" label="Rejected" />
                  </div>
                ),
              )}
            </div>
          )}
        </Card>
      )}
    </div>
  )
}

interface ActiveTechnicianRowProps {
  technician: TechnicianRow
  canApprove: boolean
  teamSelectOptions: readonly { value: string; label: string }[]
  roleSelectOptions: readonly { value: string; label: string }[]
}

/** An already-approved technician — role and team are picked here and saved together on "Assign", rather than each field saving itself the moment it changes. */
function ActiveTechnicianRow({
  technician,
  canApprove,
  teamSelectOptions,
  roleSelectOptions,
}: ActiveTechnicianRowProps) {
  const [teamId, setTeamId] = useState(technician.teamMember?.teamId ? String(technician.teamMember.teamId) : '')
  // A role saved before this page required a valid one (e.g. the old
  // free-text "Technician") won't match any option here — starting blank in
  // that case forces an explicit, valid pick instead of silently resubmitting
  // a value the backend will reject.
  const [role, setRole] = useState(
    roleSelectOptions.some((option) => option.value === technician.role) ? technician.role : '',
  )
  const [isBusy, setIsBusy] = useState(false)

  const assign = () => {
    if (role === '') return
    setIsBusy(true)
    router.put(
      routeTo.technicianTeam(technician.id),
      { team_id: teamId === '' ? null : Number(teamId), role },
      { preserveScroll: true, onFinish: () => setIsBusy(false) },
    )
  }

  return (
    <div className="flex flex-wrap items-center gap-4 rounded-panel border border-hairline bg-white/4 p-4">
      <div className="min-w-0 flex-1">
        <p className="truncate font-semibold text-white">{technician.name}</p>
        <p className="truncate text-sm text-white/60">{technician.email}</p>
      </div>
      {canApprove ? (
        <>
          <SelectField
            id={`active-role-${technician.id}`}
            aria-label={`Role for ${technician.name}`}
            className="w-40"
            value={role}
            onChange={(event) => setRole(event.target.value)}
            options={roleSelectOptions}
          />
          <SelectField
            id={`active-team-${technician.id}`}
            aria-label={`Team for ${technician.name}`}
            className="w-48"
            value={teamId}
            onChange={(event) => setTeamId(event.target.value)}
            options={teamSelectOptions}
          />
          <Button
            size="sm"
            leftIcon={Check}
            disabled={isBusy || role === ''}
            title={role === '' ? 'Pick a role before assigning' : undefined}
            onClick={assign}
          >
            Assign
          </Button>
        </>
      ) : (
        <>
          <StatusChip hideDot tone="info" label={roleLabel(technician.role)} />
          <StatusChip
            hideDot
            tone={technician.teamMember?.teamName ? 'brand' : 'neutral'}
            label={technician.teamMember?.teamName ?? 'Not on a team'}
          />
        </>
      )}
    </div>
  )
}

Teams.layout = appLayout
