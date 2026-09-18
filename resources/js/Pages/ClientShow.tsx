import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  ArrowLeft,
  ExternalLink,
  FileText,
  FolderKanban,
  PencilLine,
  Plus,
  Sparkles,
  Trash2,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  cardAccent,
  CardHeader,
  ConfirmDialog,
  EmptyState,
  StatusChip,
} from '@/components/common'
import { ClientSitesCard, type ClientSite } from '@/components/clients'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { useDisclosure } from '@/hooks'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps, TakeoffStatus } from '@/types'
import { TAKEOFF_STATUS_LABEL, TAKEOFF_STATUS_TONE, formatDate } from '@/utils'

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

export interface ClientShowProps {
  client: {
    readonly id: number
    readonly name: string
    readonly notes: string | null
    readonly createdAt: string | null
    readonly addresses: readonly ClientSite[]
  }
  /** What this client has on. The reason the screen exists. */
  projects: readonly ClientProject[]
}

/**
 * One client, and the work they have on.
 *
 * A client is a short record — a name, a note, an address book. What is worth
 * reading here is their projects, the way a job's tasks are what is worth
 * reading on a job. Drawings, takeoffs and estimates all belong to one of those
 * projects rather than to the client, so none of them appear at this level.
 */
export default function ClientShow({ client, projects }: ClientShowProps) {
  const { flash } = usePage<SharedPageProps>().props

  return (
    <PageTransition>
      <Head title={client.name} />

      <PageHeader
        title={client.name}
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
              Edit
            </ButtonLink>
            <ButtonLink href={ROUTES.clients} variant="secondary" leftIcon={ArrowLeft}>
              Back
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

      {/* ===================================================== Projects ====== */}
      <Card padding="lg" className={cardAccent('brand')}>
        <CardHeader
          title="Projects"
          subtitle={`${projects.length} on record`}
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

      <div className="mt-6 grid gap-6 xl:grid-cols-[1.2fr_1fr]">
        {/* ================================================= Address book ==== */}
        <ClientSitesCard clientId={client.id} addresses={client.addresses} />

        {/* ======================================================= Record ==== */}
        <Card padding="lg" className={cardAccent('neutral', 'min-w-0 self-start')}>
          <CardHeader title="Details" />

          <dl className="flex flex-col gap-4">
            <div className="flex flex-wrap items-baseline justify-between gap-3">
              <dt className="text-sm text-white/60">On the register since</dt>
              <dd className="text-md text-white">
                {client.createdAt ? formatDate(client.createdAt) : '—'}
              </dd>
            </div>
          </dl>

          <div className="mt-6 border-t border-hairline pt-6">
            <p className="text-sm text-white/60">Notes</p>
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

          <div className="mt-6 border-t border-hairline pt-6">
            <RemoveClient client={client} projectCount={projects.length} />
          </div>
        </Card>
      </div>
    </PageTransition>
  )
}

interface RemoveClientProps {
  client: { readonly id: number; readonly name: string }
  projectCount: number
}

/**
 * A client with projects cannot be removed: deleting them would take their
 * drawings, takeoffs, estimates and jobs with them — a delete that looks tidy
 * and quietly removes years of work.
 */
function RemoveClient({ client, projectCount }: RemoveClientProps) {
  const dialog = useDisclosure()
  const hasWork = projectCount > 0

  return (
    <>
      <Button
        variant="danger"
        leftIcon={Trash2}
        disabled={hasWork}
        onClick={dialog.open}
      >
        Remove client
      </Button>
      {hasWork && (
        <p className="mt-2 text-sm text-white/60">
          They have work on, so removing them would take it with them.
        </p>
      )}

      <ConfirmDialog
        isOpen={dialog.isOpen}
        tone="danger"
        title={`Remove “${client.name}”?`}
        description="They come off the register. This cannot be undone."
        confirmLabel="Remove client"
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
