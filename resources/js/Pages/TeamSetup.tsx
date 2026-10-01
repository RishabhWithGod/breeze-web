import { Head, router, usePage } from '@inertiajs/react'
import { ArrowRight, Pencil, Plus, RotateCw, Search, Send, Trash2, UserPlus } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Alert, Button, Card, ConfirmDialog, Modal, TextInput } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps } from '@/types'
import { cn, formatUsPhone } from '@/utils'

interface InvitationRow {
  readonly id: number
  readonly name: string
  readonly email: string
  readonly phone: string | null
  readonly role: string
  readonly teamId: number | null
  readonly teamName: string | null
  readonly status: 'pending' | 'accepted' | 'cancelled' | 'expired'
  readonly sentAt: string | null
  readonly expiresAt: string | null
}

interface Draft {
  readonly key: string
  name: string
  email: string
  phone: string
  password: string
  role: string
  teamId: string
}

export interface TeamSetupProps {
  invitations: readonly InvitationRow[]
  teams: readonly { readonly id: number; readonly name: string; readonly members: number }[]
  /** The roles this person may invite someone as. */
  roles: readonly string[]
  seats: {
    readonly used: number
    readonly pending: number
    readonly limit: number | null
    readonly available: number | null
  }
  /** Crew already on the register. */
  members: number
}

let counter = 0
const blankDraft = (role: string): Draft => ({
  key: `draft-${++counter}`,
  name: '',
  email: '',
  phone: '',
  password: '',
  role,
  teamId: '',
})

const initials = (name: string) =>
  name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word.charAt(0).toUpperCase())
    .join('') || '··'

const STATUS = {
  draft: { label: 'Not sent', className: 'border-hairline-strong bg-white/8 text-white/85' },
  pending: { label: 'Pending', className: 'border-status-warning/60 bg-status-warning/12 text-status-warning' },
  accepted: { label: 'Accepted', className: 'border-status-success/60 bg-status-success/15 text-status-success' },
  cancelled: { label: 'Cancelled', className: 'border-hairline-strong bg-white/6 text-white/60' },
  expired: { label: 'Expired', className: 'border-status-danger/50 bg-status-danger/15 text-red-300' },
} as const

const CONTROL =
  'w-full rounded-panel border border-hairline-strong bg-surface-veil/50 px-3 py-2 text-sm text-white placeholder:text-white/50 ' +
  'hover:border-brand/50 focus:border-brand focus:outline-none disabled:opacity-70'

/**
 * Team Setup — invite the crew and put them on teams, while the company is getting started.
 *
 * A table of the people being invited: name, email, phone, role and team. Sending emails each one a
 * link and holds a seat on the plan; they get their account, with that role and team, when they accept.
 * Sent invitations can be sent again or withdrawn. The teams themselves are managed on the same screen.
 * Once setup is finished the Teams register takes over.
 */
export default function TeamSetup({ invitations, teams, roles, seats, members }: TeamSetupProps) {
  const { flash, errors } = usePage<SharedPageProps>().props
  const defaultRole = roles.includes('Journeyman') ? 'Journeyman' : (roles[0] ?? 'Journeyman')

  const [drafts, setDrafts] = useState<Draft[]>(() => [blankDraft(defaultRole)])
  const [search, setSearch] = useState('')
  const [busy, setBusy] = useState(false)
  const [touched, setTouched] = useState(false)
  const [cancelling, setCancelling] = useState<InvitationRow | null>(null)

  const term = search.trim().toLowerCase()
  const matches = (name: string, email: string) =>
    term === '' || name.toLowerCase().includes(term) || email.toLowerCase().includes(term)

  // A draft that has been started counts; a blank row does not.
  const filled = useMemo(
    () => drafts.filter((draft) => draft.name.trim() !== '' || draft.email.trim() !== ''),
    [drafts],
  )
  const invalid = (draft: Draft) => draft.name.trim().length < 2 || !/^\S+@\S+\.\S+$/.test(draft.email.trim())

  const seatsLeft = seats.available === null ? null : seats.available - filled.length
  const overSeats = seatsLeft !== null && seatsLeft < 0

  const change = (key: string, patch: Partial<Draft>) =>
    setDrafts((current) => current.map((draft) => (draft.key === key ? { ...draft, ...patch } : draft)))
  const addDraft = () => setDrafts((current) => [...current, blankDraft(defaultRole)])
  const removeDraft = (key: string) =>
    setDrafts((current) =>
      current.length === 1 ? [blankDraft(defaultRole)] : current.filter((draft) => draft.key !== key),
    )

  const send = () => {
    setTouched(true)
    if (filled.length === 0 || filled.some(invalid) || overSeats) return

    router.post(
      routeTo.teamSetupInvitations,
      {
        invitations: filled.map((draft) => ({
          name: draft.name.trim(),
          email: draft.email.trim(),
          phone: draft.phone,
          // Left empty, the person is emailed a link to choose their own.
          password: draft.password === '' ? null : draft.password,
          role: draft.role,
          team_id: draft.teamId === '' ? null : Number(draft.teamId),
        })),
      },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: () => {
          setDrafts([blankDraft(defaultRole)])
          setTouched(false)
        },
      },
    )
  }

  const errorFor = (
    draft: Draft,
    field: 'name' | 'email' | 'phone' | 'password' | 'role' | 'team_id',
  ): string | undefined => {
    const index = filled.indexOf(draft)
    if (index === -1) return undefined

    return (errors as Record<string, string | undefined>)[`invitations.${index}.${field}`]
  }

  const teamOptions = (
    <>
      <option value="">Not on a team</option>
      {teams.map((team) => (
        <option key={team.id} value={team.id}>
          {team.name}
        </option>
      ))}
    </>
  )

  const shownInvitations = invitations.filter((invitation) => matches(invitation.name, invitation.email))
  const shownDrafts = drafts.filter(
    (draft) => matches(draft.name, draft.email) || (draft.name === '' && draft.email === ''),
  )

  return (
    <PageTransition>
      <Head title="Team Setup" />

      <PageHeader
        title="Team Setup"
        subtitle="Invite your crew members and assign them to teams. We'll send an invitation so they can join Breeze.Ai and get to work."
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Teams', href: ROUTES.teams },
          { label: 'Team Setup' },
        ]}
        className="mb-5"
      />

      {flash.success && (
        <Alert key={flash.success} tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}
      {flash.warning && (
        <Alert key={flash.warning} tone="warning" className="mb-4">
          {flash.warning}
        </Alert>
      )}
      {errors['invitations'] && (
        <Alert tone="danger" className="mb-4">
          {errors['invitations']}
        </Alert>
      )}

      {/* ------------------------------------------------------------------ seats -- */}
      <SeatStrip seats={seats} members={members} adding={filled.length} />

      {/* ------------------------------------------------------------------ teams -- */}
      <TeamsPanel teams={teams} />

      {/* ------------------------------------------------------------- invitations -- */}
      <Card padding="md" className="mt-4">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <TextInput
            id="setup-search"
            aria-label="Search members by name or email"
            placeholder="Search members by name or email..."
            leftIcon={Search}
            className="w-full sm:w-96"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="outline" leftIcon={UserPlus} onClick={addDraft}>
              Add another member
            </Button>
            <Button leftIcon={Send} isLoading={busy} disabled={filled.length === 0 || overSeats} onClick={send}>
              Send invitations
            </Button>
          </div>
        </div>

        <div className="overflow-x-auto rounded-panel border border-hairline">
          <table className="w-full min-w-[76rem] text-left text-sm">
            <thead>
              <tr className="border-b border-hairline bg-white/6 text-xs font-semibold tracking-wide text-white/85 uppercase">
                <th className="px-4 py-3">Name</th>
                <th className="px-3 py-3">Email</th>
                <th className="px-3 py-3">Phone</th>
                <th className="px-3 py-3">Password</th>
                <th className="px-3 py-3">Role</th>
                <th className="px-3 py-3">Team assignment</th>
                <th className="px-3 py-3">Invitation status</th>
                <th className="px-4 py-3 text-center">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-hairline">
              {shownInvitations.map((invitation) => {
                const status = STATUS[invitation.status]
                const open = invitation.status === 'pending' || invitation.status === 'expired'

                return (
                  <tr key={invitation.id} className={cn(invitation.status === 'cancelled' && 'opacity-60')}>
                    <td className="px-4 py-3">
                      <span className="flex items-center gap-3">
                        <Avatar name={invitation.name} />
                        <span className="font-medium text-white">{invitation.name}</span>
                      </span>
                    </td>
                    <td className="px-3 py-3 text-white/90">{invitation.email}</td>
                    <td className="px-3 py-3 text-white/85">{invitation.phone ?? '—'}</td>
                    <td className="px-3 py-3 text-white/50">{invitation.status === 'accepted' ? 'Set' : '—'}</td>
                    <td className="px-3 py-3 text-white/90">{invitation.role}</td>
                    <td className="px-3 py-3 text-white/90">{invitation.teamName ?? 'Not on a team'}</td>
                    <td className="px-3 py-3">
                      <Chip className={status.className}>{status.label}</Chip>
                    </td>
                    <td className="px-4 py-3">
                      <span className="flex items-center justify-center gap-1">
                        {open && (
                          <IconButton
                            label={`Resend the invitation to ${invitation.email}`}
                            onClick={() =>
                              router.post(
                                routeTo.teamSetupInvitationResend(invitation.id),
                                {},
                                { preserveScroll: true },
                              )
                            }
                          >
                            <RotateCw size={16} aria-hidden />
                          </IconButton>
                        )}
                        {invitation.status === 'pending' && (
                          <IconButton
                            label={`Cancel the invitation to ${invitation.email}`}
                            onClick={() => setCancelling(invitation)}
                          >
                            <Trash2 size={16} aria-hidden />
                          </IconButton>
                        )}
                      </span>
                    </td>
                  </tr>
                )
              })}

              {shownDrafts.map((draft) => {
                const started = draft.name.trim() !== '' || draft.email.trim() !== ''
                const show = touched && started
                const nameError =
                  errorFor(draft, 'name') ?? (show && draft.name.trim().length < 2 ? 'Enter a name.' : undefined)
                const emailError =
                  errorFor(draft, 'email') ??
                  (show && !/^\S+@\S+\.\S+$/.test(draft.email.trim()) ? 'Enter a valid email.' : undefined)

                return (
                  <tr key={draft.key}>
                    <td className="px-4 py-2.5 align-top">
                      <span className="flex items-start gap-3">
                        <Avatar name={draft.name} className="mt-1" />
                        <span className="block min-w-0 flex-1">
                          <input
                            aria-label="Name"
                            placeholder="Full name"
                            value={draft.name}
                            onChange={(event) => change(draft.key, { name: event.target.value })}
                            className={cn(CONTROL, nameError && 'border-status-danger/70')}
                          />
                          {nameError && <span className="mt-1 block text-xs text-red-300">{nameError}</span>}
                        </span>
                      </span>
                    </td>
                    <td className="px-3 py-2.5 align-top">
                      <input
                        aria-label="Email"
                        type="email"
                        placeholder="name@company.com"
                        value={draft.email}
                        onChange={(event) => change(draft.key, { email: event.target.value })}
                        className={cn(CONTROL, emailError && 'border-status-danger/70')}
                      />
                      {emailError && <span className="mt-1 block text-xs text-red-300">{emailError}</span>}
                    </td>
                    <td className="px-3 py-2.5 align-top">
                      <input
                        aria-label="Phone"
                        type="tel"
                        inputMode="tel"
                        placeholder="(555) 555-0123"
                        value={draft.phone}
                        onChange={(event) => change(draft.key, { phone: formatUsPhone(event.target.value) })}
                        className={cn(CONTROL, errorFor(draft, 'phone') && 'border-status-danger/70')}
                      />
                      {errorFor(draft, 'phone') && (
                        <span className="mt-1 block text-xs text-red-300">{errorFor(draft, 'phone')}</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 align-top">
                      <input
                        aria-label="Password"
                        type="password"
                        autoComplete="new-password"
                        placeholder="Optional · min 8"
                        value={draft.password}
                        onChange={(event) => change(draft.key, { password: event.target.value })}
                        className={cn(CONTROL, errorFor(draft, 'password') && 'border-status-danger/70')}
                      />
                      {errorFor(draft, 'password') && (
                        <span className="mt-1 block text-xs text-red-300">{errorFor(draft, 'password')}</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 align-top">
                      <select
                        aria-label="Role"
                        value={draft.role}
                        onChange={(event) => change(draft.key, { role: event.target.value })}
                        className={cn(CONTROL, '[&>option]:bg-navy-900')}
                      >
                        {roles.map((role) => (
                          <option key={role} value={role}>
                            {role}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="px-3 py-2.5 align-top">
                      <select
                        aria-label="Team assignment"
                        value={draft.teamId}
                        onChange={(event) => change(draft.key, { teamId: event.target.value })}
                        className={cn(CONTROL, '[&>option]:bg-navy-900')}
                      >
                        {teamOptions}
                      </select>
                    </td>
                    <td className="px-3 py-2.5 align-top">
                      <Chip className={STATUS.draft.className}>{STATUS.draft.label}</Chip>
                    </td>
                    <td className="px-4 py-2.5 align-top">
                      <span className="flex justify-center">
                        <IconButton label="Remove this row" onClick={() => removeDraft(draft.key)}>
                          <Trash2 size={16} aria-hidden />
                        </IconButton>
                      </span>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          <Button variant="outline" leftIcon={Plus} onClick={addDraft}>
            Add another member
          </Button>
          <div className="flex items-center gap-4">
            {overSeats && (
              <span className="text-sm text-red-300">
                Only {seats.available} {seats.available === 1 ? 'seat is' : 'seats are'} left on your plan.
              </span>
            )}
            <Button leftIcon={Send} isLoading={busy} disabled={filled.length === 0 || overSeats} onClick={send}>
              Send invitations
            </Button>
            <Button variant="outline" rightIcon={ArrowRight} onClick={() => router.visit(ROUTES.commodities)}>
              Continue to Commodity List
            </Button>
          </div>
        </div>
      </Card>

      <ConfirmDialog
        isOpen={cancelling !== null}
        title="Cancel this invitation?"
        description={
          cancelling ? `The link sent to ${cancelling.email} will stop working, and the seat is free again.` : undefined
        }
        confirmLabel="Cancel invitation"
        confirmVariant="danger"
        tone="danger"
        onConfirm={() => {
          if (cancelling)
            router.delete(routeTo.teamSetupInvitationCancel(cancelling.id), {
              preserveScroll: true,
              onFinish: () => setCancelling(null),
            })
        }}
        onCancel={() => setCancelling(null)}
      />
    </PageTransition>
  )
}

TeamSetup.layout = appLayout

function Avatar({ name, className }: { name: string; className?: string }) {
  return (
    <span
      className={cn(
        'grid size-9 shrink-0 place-items-center rounded-full bg-brand/25 text-xs font-bold text-white ring-1 ring-brand/40',
        className,
      )}
    >
      {initials(name)}
    </span>
  )
}

function Chip({ className, children }: { className: string; children: React.ReactNode }) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold whitespace-nowrap',
        className,
      )}
    >
      {children}
    </span>
  )
}

function IconButton({ label, onClick, children }: { label: string; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      onClick={onClick}
      className="grid size-8 place-items-center rounded-full text-white/75 transition-colors hover:bg-white/12 hover:text-white"
    >
      {children}
    </button>
  )
}

/** How many seats the plan has, how many are taken, and how many the list below would use. */
function SeatStrip({ seats, members, adding }: { seats: TeamSetupProps['seats']; members: number; adding: number }) {
  return (
    <div className="mb-4 flex flex-wrap items-center gap-x-8 gap-y-2 rounded-card border border-hairline glass px-5 py-3 text-sm">
      <span className="font-semibold text-white">Seat availability</span>
      <span className="text-white/85">
        <strong className="text-white tabular-nums">{seats.used}</strong> in use
      </span>
      <span className="text-white/85">
        <strong className="text-white tabular-nums">{seats.pending}</strong> invited, waiting
      </span>
      {adding > 0 && (
        <span className="text-white/85">
          <strong className="text-white tabular-nums">{adding}</strong> on this list
        </span>
      )}
      <span className={cn('ml-auto font-semibold', seats.available === 0 ? 'text-red-300' : 'text-brand')}>
        {seats.limit === null ? 'Unlimited seats' : `${seats.available} of ${seats.limit} seats available`}
      </span>
      {members > 0 && (
        <span className="w-full text-xs text-white/60 sm:w-auto">{members} already on the crew register.</span>
      )}
    </div>
  )
}

/** The teams people are put on: add one, rename it, or delete an empty one. */
function TeamsPanel({ teams }: { teams: TeamSetupProps['teams'] }) {
  const [name, setName] = useState('')
  const [renaming, setRenaming] = useState<{ id: number; name: string } | null>(null)
  const { errors } = usePage<SharedPageProps>().props

  const add = (event: React.FormEvent) => {
    event.preventDefault()
    if (name.trim() === '') return
    router.post(
      ROUTES.teams,
      { name: name.trim(), inline: true },
      { preserveScroll: true, onSuccess: () => setName('') },
    )
  }

  return (
    <Card padding="md">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-white">Teams</h2>
          <p className="text-xs text-white/70">Add the crews first, then choose one for each person below.</p>
        </div>
        <form onSubmit={add} className="flex items-start gap-2">
          <TextInput
            id="new-team"
            aria-label="New team name"
            placeholder="New team name"
            maxLength={120}
            className="w-56"
            value={name}
            onChange={(event) => setName(event.target.value)}
            {...(errors['name'] ? { error: errors['name'] } : {})}
          />
          <Button type="submit" variant="secondary" leftIcon={Plus} disabled={name.trim() === ''}>
            Add team
          </Button>
        </form>
      </div>

      {teams.length === 0 ? (
        <p className="mt-3 text-sm text-white/70">
          No teams yet. People can be invited without one and put on a team later.
        </p>
      ) : (
        <ul className="mt-4 flex flex-wrap gap-2.5">
          {teams.map((team) => (
            <li
              key={team.id}
              className="flex items-center gap-2 rounded-full border border-hairline-strong bg-white/6 py-1.5 pr-2 pl-4 text-sm text-white"
            >
              <span className="font-semibold">{team.name}</span>
              <span className="text-xs text-white/65">
                {team.members} {team.members === 1 ? 'member' : 'members'}
              </span>
              <IconButton label={`Rename ${team.name}`} onClick={() => setRenaming({ id: team.id, name: team.name })}>
                <Pencil size={14} aria-hidden />
              </IconButton>
              {team.members === 0 && (
                <IconButton
                  label={`Delete ${team.name}`}
                  onClick={() => router.delete(routeTo.team(team.id), { preserveScroll: true })}
                >
                  <Trash2 size={14} aria-hidden />
                </IconButton>
              )}
            </li>
          ))}
        </ul>
      )}

      {renaming && <RenameModal team={renaming} onClose={() => setRenaming(null)} />}
    </Card>
  )
}

function RenameModal({ team, onClose }: { team: { id: number; name: string }; onClose: () => void }) {
  const [value, setValue] = useState(team.name)
  const { errors } = usePage<SharedPageProps>().props

  const save = () =>
    router.put(routeTo.team(team.id), { name: value.trim() }, { preserveScroll: true, onSuccess: onClose })

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
          <Button disabled={value.trim() === '' || value.trim() === team.name} onClick={save}>
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
          id="rename-setup-team"
          label="Team name"
          autoFocus
          maxLength={120}
          value={value}
          onChange={(event) => setValue(event.target.value)}
          {...(errors['name'] ? { error: errors['name'] } : {})}
        />
      </form>
    </Modal>
  )
}
