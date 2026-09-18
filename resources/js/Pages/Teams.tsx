import { useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { Check, Eye, HardHat, UserCheck, UserPlus, Users, X } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  EmptyState,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { Paginated, SharedPageProps, TableColumn, Tone } from '@/types'
import { formatDate, formatHours } from '@/utils'

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
  /** Every team, unpaginated — for the "assign a team" picker. */
  teamOptions: readonly TeamOption[]
  /** 'Foreman' / 'Journeyman' / 'Apprentice' — what a mobile signup can be corrected to. */
  technicianRoleOptions: readonly string[]
}

/** The task list, narrowed to one member — what a row's numbers describe. */
const tasksFor = (name: string) => `${ROUTES.tasks}?foreman=${encodeURIComponent(name)}`

/** The stored value is already the label — kept as a function so every call site reads the same way. */
const roleLabel = (role: string) => role

/** A foreman reads differently from a journeyman or apprentice at a glance — the point of toning the chip at all. */
const ROLE_TONE: Record<string, Tone> = {
  foreman: 'info',
  journeyman: 'neutral',
  apprentice: 'brand',
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
  teamOptions,
  technicianRoleOptions,
}: TeamsProps) {
  const [search, setSearch] = useState(filters.search)
  const { flash } = usePage<SharedPageProps>().props
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
      /* Toned, not just written: a foreman reads differently from a journeyman
         or apprentice at a glance, which is the point of showing it in the
         crew's own list. */
      render: (row) => (
        <StatusChip hideDot tone={ROLE_TONE[row.role] ?? 'neutral'} label={row.roleLabel} />
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

      {pendingTechnicians.length > 0 && (
        <TechnicianApprovalSection
          pendingTechnicians={pendingTechnicians}
          canApprove={canApproveTechnicians}
          teamOptions={teamOptions}
          roleOptions={technicianRoleOptions}
        />
      )}

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
          {groups.map((team, index) => (
            <TeamCard
              key={team.id}
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

      {(activeTechnicians.length > 0 || rejectedTechnicians.length > 0) && (
        <TechnicianRegisterSection
          activeTechnicians={activeTechnicians}
          rejectedTechnicians={rejectedTechnicians}
          canApprove={canApproveTechnicians}
          teamOptions={teamOptions}
          roleOptions={technicianRoleOptions}
        />
      )}
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
  /** Position in the list, which is what picks the card's accent colour. */
  index: number
}

/** One crew and everyone on it. */
function TeamCard({ name, description, members, columns, canManage, index }: TeamCardProps) {
  return (
    /* A colour per crew, cycling down the list the way every other list on the
       screen does — one repeated accent makes a page of crews read as one. */
    <Card accent="auto" index={index} padding="lg">
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
      <div className="mb-4">
        <h2 className="flex items-center gap-2 text-lg font-semibold text-white">
          <UserCheck size={18} className="text-brand" />
          Technicians waiting for approval
        </h2>
        <p className="mt-1 text-sm text-white/70">
          Signed up from the mobile app. {pendingTechnicians.length}{' '}
          {pendingTechnicians.length === 1 ? 'technician' : 'technicians'} cannot use the app
          until approved.
        </p>
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
  // flow as a pending one, so the picker here needs a real placeholder
  // rather than the "No team yet" default the active-correction pickers use.
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
            <p className="mt-1 text-sm text-white/70">
              {activeTechnicians.length} approved from the mobile app.
            </p>
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
            <Button
              size="sm"
              variant="secondary"
              onClick={() => setShowRejected((value) => !value)}
            >
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
  const [teamId, setTeamId] = useState(
    technician.teamMember?.teamId ? String(technician.teamMember.teamId) : '',
  )
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
