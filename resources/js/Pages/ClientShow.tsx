import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { Clock, Globe, Mail, MapPin, PencilLine, Phone, Plus, Trash2 } from 'lucide-react'
import {
  Alert,
  Badge,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Panel,
  Stat,
  Table,
} from '@/components/common'
import { ClientContactsCard, ClientSitesCard, type ClientContact, type ClientSite } from '@/components/clients'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { useDisclosure, usePermissions } from '@/hooks'
import { ROUTES, routeTo } from '@/constants'
import type { ClientTeamMemberOption, SharedPageProps, TableColumn, TakeoffStatus, Tone } from '@/types'
import {
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  formatCurrency,
  formatDate,
  cn,
  formatRelative,
} from '@/utils'

interface ClientProject {
  readonly id: number
  readonly name: string
  readonly code: string | null
  readonly status: TakeoffStatus
  readonly projectType: string | null
  readonly drawingCount: number
  readonly takeoffCount: number
  readonly createdAt: string | null
}

interface ProjectSummary {
  readonly total: number
  readonly active: number
  readonly completed: number
  readonly onHold: number
}

interface ActivityEntry {
  readonly id: number
  readonly title: string
  readonly description: string | null
  readonly tone: Tone
  readonly occurredAt: string | null
}

export interface ClientShowProps {
  client: {
    readonly id: number
    readonly name: string
    readonly website: string | null
    /** This client's own line — read off their primary contact. */
    readonly contactEmail: string | null
    readonly contactPhone: string | null
    readonly notes: string | null
    readonly createdAt: string | null
    readonly contacts: readonly ClientContact[]
    readonly addresses: readonly ClientSite[]
  }
  /** Who normally works this client's sites — picked on Add Client, independent of any team. */
  members: readonly ClientTeamMemberOption[]
  /** What this client has on. The reason the screen exists. */
  projects: readonly ClientProject[]
  /** At-a-glance counts over the list above. */
  projectSummary: ProjectSummary
  /** Every invoice raised against this client, still owed. */
  outstandingBalance: number
  /** Everything invoiced to this client so far — sent and paid invoices, not drafts. */
  billedToDate: number
  /** What an hour of this client's labor is billed at: their own rate, else the usual one. */
  laborRate: number
  /** What has actually happened, read off this client's own projects. */
  activity: readonly ActivityEntry[]
}

/**
 * One client, and the work they have on.
 *
 * A client is a short record — a name, a note, an address book, the people
 * to call. What is worth reading here is their projects, the way a job's
 * tasks are what is worth reading on a job. Drawings, takeoffs and estimates
 * all belong to one of those projects rather than to the client, so none of
 * them appear at this level.
 */
const MEMBER_ROLE_TONE: Record<string, Tone> = {
  foreman: 'info',
  journeyman: 'neutral',
  apprentice: 'brand',
}

export default function ClientShow({
  client,
  members,
  projects,
  projectSummary,
  outstandingBalance,
  billedToDate,
  laborRate,
  activity,
}: ClientShowProps) {
  const { can: permitted } = usePermissions()
  const { flash } = usePage<SharedPageProps>().props

  const activityColumns: TableColumn<ActivityEntry>[] = [
    {
      key: 'activity',
      header: 'Activity',
      render: (entry) => (
        <span className="flex items-center gap-2">
          <Clock size={14} aria-hidden className="shrink-0 text-[var(--text-3)]" />
          {entry.title}
        </span>
      ),
    },
    {
      key: 'details',
      header: 'Details',
      render: (entry) => (
        <span className="text-[var(--text-2)]">{entry.description ?? '—'}</span>
      ),
    },
    {
      key: 'time',
      header: 'Time',
      align: 'right',
      render: (entry) => (
        <span className="whitespace-nowrap text-[var(--text-3)]">
          {entry.occurredAt ? formatRelative(entry.occurredAt) : '—'}
        </span>
      ),
    },
  ]

  const projectColumns: TableColumn<ClientProject>[] = [
    {
      key: 'project',
      header: 'Project',
      render: (project) => (
        <div className="min-w-0">
          <Link href={routeTo.project(project.id)} className="block truncate font-bold text-white hover:text-brand">
            {project.name}
          </Link>
          <p className="truncate text-[13px] text-[var(--text-3)]">
            {project.drawingCount} {project.drawingCount === 1 ? 'drawing' : 'drawings'} · {project.takeoffCount}{' '}
            {project.takeoffCount === 1 ? 'takeoff' : 'takeoffs'}
          </p>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (project) => (
        <Badge tone={TAKEOFF_STATUS_TONE[project.status]}>{TAKEOFF_STATUS_LABEL[project.status]}</Badge>
      ),
    },
    {
      key: 'opened',
      header: 'Opened',
      render: (project) => (
        <span className="whitespace-nowrap">{project.createdAt ? formatDate(project.createdAt) : '—'}</span>
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title={client.name} />

      <PageHeader
        title={client.name}
        {...(client.createdAt ? { subtitle: `Client since ${formatDate(client.createdAt)}` } : {})}
        breadcrumbs={[
          { label: 'Clients', href: ROUTES.clients },
          { label: client.name },
        ]}
        actions={
          <>
            {permitted('clients.edit') && (
              <ButtonLink href={routeTo.clientEdit(client.id)} variant="secondary" leftIcon={PencilLine}>
                Edit
              </ButtonLink>
            )}
            {permitted('projects.create') && (
              <ButtonLink href={routeTo.projectCreateForClient(client.id)} leftIcon={Plus}>
                New project
              </ButtonLink>
            )}
            <RemoveClient client={client} projectCount={projects.length} />
          </>
        }
      />

      {/* A refused delete lands here, and it explains itself. */}
      <AnimatePresence initial={false}>
        {flash.warning && (
          <Alert key={flash.warning} tone="warning" className="mb-4">
            {flash.warning}
          </Alert>
        )}
      </AnimatePresence>

      {/* ================================================== Stat tiles ====== */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Labor rate" value={`${formatCurrency(laborRate, 2)}/hr`} />
        <Stat label="Projects" value={projectSummary.total} />
        <Stat label="Billed to date" value={<InvoicesLink name={client.name}>{formatCurrency(billedToDate, 0)}</InvoicesLink>} />
        <Stat label="Open balance" value={<InvoicesLink name={client.name}>{formatCurrency(outstandingBalance, 0)}</InvoicesLink>} />
      </div>

      {/* ================================================== Body grid ======= */}
      <div className="mt-4 grid items-stretch gap-4 lg:grid-cols-2 xl:grid-cols-3">
        {/* Cards run in rows so each row stands one height: Projects with the client's details, three smaller cards, then the activity log across the bottom. */}
          <Panel
            id="projects"
            title="Projects"
            pad={false}
            className="scroll-mt-6 xl:col-span-2"
            actions={
              <ButtonLink href={routeTo.projectCreateForClient(client.id)} variant="ghost" size="sm" leftIcon={Plus}>
                Add
              </ButtonLink>
            }
          >
            {projects.length === 0 ? (
              <EmptyState
                size="sm"
                icon={MapPin}
                title="Nothing on for this client yet"
                description="Open a project, then run its drawings through AI Takeoff."
                actions={
                  <ButtonLink href={routeTo.projectCreateForClient(client.id)} leftIcon={Plus}>
                    Add project
                  </ButtonLink>
                }
              />
            ) : (
              <Table
                variant="lined"
                columns={projectColumns}
                rows={projects}
                getRowId={(project) => project.id}
                caption="Projects for this client"
                onRowClick={(project) => router.visit(routeTo.project(project.id))}
              />
            )}
          </Panel>

          <Panel title="Client information">
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
              <KvItem label="Name" value={client.name} />
              <KvItem label="On the register since" value={client.createdAt ? formatDate(client.createdAt) : null} />
              <KvItem label="Phone" icon={Phone} value={client.contactPhone && <a href={`tel:${client.contactPhone}`} className="text-brand hover:underline">{client.contactPhone}</a>} />
              <KvItem label="Email" icon={Mail} value={client.contactEmail && <a href={`mailto:${client.contactEmail}`} className="text-brand hover:underline">{client.contactEmail}</a>} />
              <KvItem
                className="col-span-2"
                label="Website"
                icon={Globe}
                value={
                  client.website && (
                    <a href={client.website} target="_blank" rel="noreferrer" className="text-brand hover:underline">
                      {client.website.replace(/^https?:\/\//, '')}
                    </a>
                  )
                }
              />
              <KvItem
                className="col-span-2"
                label="Notes"
                value={client.notes ? <span className="whitespace-pre-line">{client.notes}</span> : null}
                emptyLabel="Nothing recorded."
              />
            </dl>
          </Panel>

          <ClientSitesCard clientId={client.id} addresses={client.addresses} />

          <ClientContactsCard clientId={client.id} contacts={client.contacts} />

          <Panel title="Crew">
            {members.length === 0 ? (
              <p className="text-[var(--text-3)]">No one on record yet.</p>
            ) : (
              <ul>
                {members.map((member) => (
                  <li
                    key={member.id}
                    className="flex items-center justify-between gap-3 border-b border-hairline py-[11px] first:pt-0 last:border-b-0 last:pb-0"
                  >
                    <span className="font-medium text-white">{member.name}</span>
                    <Badge tone={MEMBER_ROLE_TONE[member.role] ?? 'neutral'}>{member.roleLabel}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel
            title="Recent activity"
            className="lg:col-span-2 xl:col-span-3"
            pad={false}
            actions={
              activity.length > 0 ? <span className="text-[13px] font-medium text-brand">View all activity →</span> : undefined
            }
          >
            {activity.length === 0 ? (
              <p className="p-[22px] text-[var(--text-3)]">
                Nothing recorded yet — activity shows up here once there's a project to report on.
              </p>
            ) : (
              <Table
                columns={activityColumns}
                rows={activity}
                getRowId={(entry) => entry.id}
                variant="lined"
                caption="Recent activity on this client"
              />
            )}
          </Panel>
      </div>
    </PageTransition>
  )
}

/** A figure that opens this client's invoices. */
function InvoicesLink({ name, children }: { name: string; children: React.ReactNode }) {
  return (
    <Link
      href={`${ROUTES.invoices}?client=${encodeURIComponent(name)}`}
      title="View this client's invoices"
      className="transition-colors hover:text-brand"
    >
      {children}
    </Link>
  )
}

interface KvItemProps {
  label: string
  value: React.ReactNode | null
  icon?: React.ComponentType<{ size?: number; className?: string; 'aria-hidden'?: boolean }>
  emptyLabel?: string
  className?: string
}

/** One label-over-value pair, as the Breeze app's key/value list draws it. */
function KvItem({ label, value, icon: Icon, emptyLabel = '—', className }: KvItemProps) {
  return (
    <div className={cn('min-w-0', className)}>
      <dt className="mb-0.5 flex items-center gap-[5px] text-xs text-[var(--text-3)]">
        {Icon && <Icon size={12} aria-hidden className="shrink-0" />}
        {label}
      </dt>
      <dd className="font-medium break-words text-white">{value ?? <span className="text-[var(--text-3)]">{emptyLabel}</span>}</dd>
    </div>
  )
}

interface RemoveClientProps {
  client: { readonly id: number; readonly name: string }
  projectCount: number
}

/**
 * Removing a client removes everything under them: their projects, each
 * project's AI takeoffs, and the jobs, tasks and schedules built from them.
 * The dialog says so, because the button itself looks no different for a client
 * with years of work than for one with none.
 */
function RemoveClient({ client, projectCount }: RemoveClientProps) {
  const dialog = useDisclosure()
  const hasWork = projectCount > 0

  return (
    <>
      <MoreMenu
        variant="minimal"
        ariaLabel={`More actions for ${client.name}`}
        items={[{ label: 'Remove client', icon: Trash2, destructive: true, onSelect: dialog.open }]}
      />

      <ConfirmDialog
        isOpen={dialog.isOpen}
        tone="danger"
        title={`Remove “${client.name}”?`}
        description={
          hasWork
            ? `This also deletes their ${projectCount} ${projectCount === 1 ? 'project' : 'projects'} and everything under ${projectCount === 1 ? 'it' : 'them'} — AI takeoffs, jobs, tasks and schedules. This cannot be undone.`
            : 'They come off the register. This cannot be undone.'
        }
        confirmLabel={hasWork ? 'Delete client and all work' : 'Remove client'}
        confirmVariant="danger"
        onConfirm={() => {
          router.delete(routeTo.client(client.id))
          dialog.close()
        }}
        onCancel={dialog.close}
      />
    </>
  )
}

ClientShow.layout = appLayout
