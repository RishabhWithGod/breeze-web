import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  CircleDollarSign,
  ClipboardList,
  Clock,
  DollarSign,
  ExternalLink,
  FileText,
  FolderKanban,
  Globe,
  Mail,
  PencilLine,
  Phone,
  Plus,
  Sparkles,
  Trash2,
  User,
} from 'lucide-react'
import {
  Alert,
  Badge,
  ButtonLink,
  Card,
  CardHeader,
  ConfirmDialog,
  EmptyState,
  IconButton,
  StatusChip,
  Table,
} from '@/components/common'
import { ClientContactsCard, ClientSitesCard, type ClientContact, type ClientSite } from '@/components/clients'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { useDisclosure } from '@/hooks'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps, TableColumn, TakeoffStatus, Tone } from '@/types'
import {
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  formatCurrency,
  formatDate,
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
  /** What this client has on. The reason the screen exists. */
  projects: readonly ClientProject[]
  /** At-a-glance counts over the list above. */
  projectSummary: ProjectSummary
  /** Every invoice raised against this client, still owed. */
  outstandingBalance: number
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
export default function ClientShow({
  client,
  projects,
  projectSummary,
  outstandingBalance,
  activity,
}: ClientShowProps) {
  const { flash } = usePage<SharedPageProps>().props

  const activityColumns: TableColumn<ActivityEntry>[] = [
    {
      key: 'activity',
      header: 'Activity',
      width: 'w-56',
      render: (entry) => (
        <span className="flex items-center gap-2">
          <Clock size={14} aria-hidden className="shrink-0 text-white/50" />
          {entry.title}
        </span>
      ),
    },
    {
      key: 'details',
      header: 'Details',
      render: (entry) => (
        <span className="text-white/80">{entry.description ?? '—'}</span>
      ),
    },
    {
      key: 'time',
      header: 'Time',
      align: 'right',
      width: 'w-36',
      render: (entry) => (
        <span className="text-white/60">
          {entry.occurredAt ? formatRelative(entry.occurredAt) : '—'}
        </span>
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title={client.name} />

      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {client.name}
            <Badge tone="info" size="sm">
              Client
            </Badge>
          </span>
        }
        breadcrumbs={[
          { label: 'Clients', href: ROUTES.clients },
          { label: client.name },
        ]}
        actions={
          <>
            <ButtonLink
              href={routeTo.clientEdit(client.id)}
              variant="secondary"
              leftIcon={PencilLine}
            >
              Edit Client
            </ButtonLink>
            <ButtonLink href={routeTo.projectCreateForClient(client.id)} leftIcon={Plus}>
              Add Project
            </ButtonLink>
          </>
        }
      />

      {/* A refused delete lands here, and it explains itself. */}
      <AnimatePresence initial={false}>
        {flash.warning && (
          <Alert key={flash.warning} tone="warning" className="mb-6">
            {flash.warning}
          </Alert>
        )}
      </AnimatePresence>

      {/* ============================================ Information row ====== */}
      <div className="grid gap-6 xl:grid-cols-3">
        <Card padding="md" className="min-w-0">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <User size={18} aria-hidden className="text-white/70" />
                Client Information
              </span>
            }
            actions={<RemoveClient client={client} projectCount={projects.length} />}
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          <dl className="flex flex-col gap-3">
            <InfoRow label="Name" value={client.name} />
            <InfoRow label="Phone" value={client.contactPhone} icon={Phone} />
            <InfoRow label="Email" value={client.contactEmail} icon={Mail} />
            <InfoRow
              label="Website"
              icon={Globe}
              value={
                client.website ? (
                  <a
                    href={client.website}
                    target="_blank"
                    rel="noreferrer"
                    className="text-brand hover:underline"
                  >
                    {client.website.replace(/^https?:\/\//, '')}
                  </a>
                ) : null
              }
            />
          </dl>

          <dl className="mt-6 flex flex-col gap-3 border-t border-hairline pt-6">
            <InfoRow label="On the register since" value={client.createdAt ? formatDate(client.createdAt) : null} />
          </dl>

          <div className="mt-6 border-t border-hairline pt-6">
            <p className="flex items-center gap-1.5 text-sm text-white/60">
              <FileText size={13} aria-hidden className="shrink-0" />
              Notes
            </p>
            <p
              className={
                client.notes
                  ? 'mt-1 text-md whitespace-pre-line text-white/90'
                  : 'mt-1 text-md text-white/45'
              }
            >
              {client.notes ?? 'Nothing recorded.'}
            </p>
          </div>
        </Card>

        <ClientContactsCard clientId={client.id} contacts={client.contacts} />

        <ClientSitesCard clientId={client.id} addresses={client.addresses} />
      </div>

      {/* ==================================== Summary + Balance row ====== */}
      <div className="mt-6 grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <Card padding="md" className="min-w-0">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <ClipboardList size={18} aria-hidden className="text-white/70" />
                Project Summary
              </span>
            }
            actions={
              <a href="#projects" className="text-sm text-brand hover:underline">
                View All Projects →
              </a>
            }
            className="border-b border-hairline pb-4 sm:items-center"
            titleClassName="text-lg font-semibold"
          />

          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <SummaryStat label="Total Projects" value={projectSummary.total} />
            <SummaryStat label="Active Projects" value={projectSummary.active} tone="brand" />
            <SummaryStat label="Completed Projects" value={projectSummary.completed} />
            <SummaryStat label="On Hold" value={projectSummary.onHold} />
          </div>
        </Card>

        <Card padding="md" className="min-w-0">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <CircleDollarSign size={18} aria-hidden className="text-white/70" />
                Outstanding Balance
              </span>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="text-3xl font-semibold text-white">
                {formatCurrency(outstandingBalance, 2)}
              </p>
              <p className="mt-1 text-sm text-white/60">Total Outstanding</p>
            </div>

            <ButtonLink
              href={`${ROUTES.invoices}?client=${encodeURIComponent(client.name)}`}
              size="sm"
              leftIcon={DollarSign}
            >
              View Invoices
            </ButtonLink>
          </div>
        </Card>
      </div>

      {/* ===================================================== Projects ====== */}
      <Card id="projects" padding="md" className="mt-6 scroll-mt-6">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <FolderKanban size={18} aria-hidden className="text-white/70" />
              Projects
            </span>
          }
          actions={
            <ButtonLink
              href={routeTo.projectCreateForClient(client.id)}
              variant="secondary"
              size="sm"
              leftIcon={Plus}
            >
              Add project
            </ButtonLink>
          }
          className="border-b border-hairline pb-4 sm:items-center"
          titleClassName="text-lg font-semibold"
        />

        {projects.length === 0 ? (
          <EmptyState
            size="sm"
            icon={FolderKanban}
            title="Nothing on for this client yet"
            description="Open a project, then run its drawings through AI Takeoff."
            actions={
              <ButtonLink
                href={routeTo.projectCreateForClient(client.id)}
                leftIcon={Plus}
              >
                Add project
              </ButtonLink>
            }
          />
        ) : (
          <ul className="space-y-3">
            {projects.map((project, index) => (
              <motion.li
                key={project.id}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.25, delay: Math.min(index, 6) * 0.04 }}
                className="rounded-panel border border-hairline bg-white/4 p-4"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <Link
                      href={routeTo.project(project.id)}
                      className="truncate font-semibold text-white transition-colors hover:text-brand"
                    >
                      {project.name}
                    </Link>
                    <p className="mt-0.5 flex flex-wrap items-center gap-x-3 text-sm text-white/70">
                      <span className="flex items-center gap-1.5">
                        <FileText size={13} aria-hidden />
                        {project.drawingCount}{' '}
                        {project.drawingCount === 1 ? 'drawing' : 'drawings'}
                      </span>
                      <span className="flex items-center gap-1.5">
                        <Sparkles size={13} aria-hidden />
                        {project.takeoffCount}{' '}
                        {project.takeoffCount === 1 ? 'takeoff' : 'takeoffs'}
                      </span>
                      {project.createdAt && <span>Opened {formatDate(project.createdAt)}</span>}
                    </p>
                  </div>

                  <div className="flex items-center gap-3">
                    <StatusChip
                      hideDot
                      tone={TAKEOFF_STATUS_TONE[project.status]}
                      label={TAKEOFF_STATUS_LABEL[project.status]}
                    />
                    <ButtonLink
                      href={routeTo.project(project.id)}
                      variant="ghost"
                      size="sm"
                      leftIcon={ExternalLink}
                    >
                      Open
                    </ButtonLink>
                  </div>
                </div>
              </motion.li>
            ))}
          </ul>
        )}
      </Card>

      {/* ================================================ Recent Activity ==== */}
      <Card padding="md" className="mt-6">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <Clock size={18} aria-hidden className="text-white/70" />
              Recent Activity
            </span>
          }
          actions={
            activity.length > 0 ? (
              <span className="text-sm text-brand">View All Activity →</span>
            ) : undefined
          }
          className="border-b border-hairline pb-4 sm:items-center"
          titleClassName="text-lg font-semibold"
        />

        {activity.length === 0 ? (
          <p className="text-md text-white/70">
            Nothing recorded yet — activity shows up here once there's a project to report on.
          </p>
        ) : (
          <Table
            columns={activityColumns}
            rows={activity}
            getRowId={(entry) => entry.id}
            variant="lined"
            dense
            caption="Recent activity on this client"
          />
        )}
      </Card>
    </PageTransition>
  )
}

interface InfoRowProps {
  label: string
  value: React.ReactNode | null
  icon?: React.ComponentType<{ size?: number; className?: string; 'aria-hidden'?: boolean }>
}

function InfoRow({ label, value, icon: Icon }: InfoRowProps) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-3">
      <dt className="flex items-center gap-1.5 text-sm text-white/60">
        {Icon && <Icon size={13} aria-hidden className="shrink-0" />}
        {label}
      </dt>
      <dd className="text-md text-white">{value ?? <span className="text-white/45">—</span>}</dd>
    </div>
  )
}

interface SummaryStatProps {
  label: string
  value: number
  tone?: Tone
}

function SummaryStat({ label, value, tone = 'neutral' }: SummaryStatProps) {
  const TONE_TEXT: Record<Tone, string> = {
    brand: 'text-brand',
    success: 'text-status-success',
    warning: 'text-status-warning',
    danger: 'text-red-300',
    info: 'text-status-info',
    purple: 'text-status-purple',
    blue: 'text-status-blue',
    neutral: 'text-white',
  }

  return (
    <div>
      <p className={`text-2xl font-semibold tabular-nums ${TONE_TEXT[tone]}`}>{value}</p>
      <p className="text-sm text-white/60">{label}</p>
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
      <IconButton
        icon={Trash2}
        label={`Remove ${client.name}`}
        variant="danger"
        size="sm"
        onClick={dialog.open}
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
