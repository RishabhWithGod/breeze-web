import { useState } from 'react'
import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  ArrowLeft,
  ExternalLink,
  FileText,
  FolderKanban,
  MapPin,
  PencilLine,
  Plus,
  Sparkles,
  Trash2,
} from 'lucide-react'
import {
  AddressField,
  Alert,
  Button,
  ButtonLink,
  Card,
  cardAccent,
  CardHeader,
  ConfirmDialog,
  EmptyState,
  IconButton,
  Modal,
  SelectField,
  StatusChip,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { useDisclosure } from '@/hooks'
import { ROUTES, routeTo, SITE_TYPE_OPTIONS } from '@/constants'
import type { SharedPageProps, TakeoffStatus } from '@/types'
import { TAKEOFF_STATUS_LABEL, TAKEOFF_STATUS_TONE, formatDate, toTitleCase } from '@/utils'

interface ClientSite {
  readonly id: number
  readonly label: string | null
  readonly address: string
  readonly display: string
  /** What kind of building it is. A job raised here starts from it. */
  readonly siteType: string | null
  readonly isPrimary: boolean
  readonly latitude: number | null
  readonly longitude: number | null
  readonly placeId: string | null
  /** How many jobs are standing on it. A site with work on it cannot go. */
  readonly jobCount: number
}

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
  const [removingSite, setRemovingSite] = useState<ClientSite | null>(null)
  const [editingSite, setEditingSite] = useState<ClientSite | null>(null)

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
        <Card padding="lg" className={cardAccent('success', 'min-w-0')}>
          <CardHeader
            title="Site Location(s)"
            subtitle="One book, shared by every project of theirs"
          />

          {client.addresses.length === 0 ? (
            <p className="text-md text-white/70">
              No sites on record yet. One is added the first time a project needs it.
            </p>
          ) : (
            <ul className="space-y-2">
              {client.addresses.map((site) => (
                <li
                  key={site.id}
                  className="flex items-start gap-3 rounded-panel border border-hairline bg-white/4 p-3"
                >
                  <MapPin size={16} aria-hidden className="mt-0.5 shrink-0 text-white/60" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-md text-white">{site.display}</span>
                    <span className="flex flex-wrap items-center gap-x-3 text-sm">
                      {site.isPrimary && <span className="text-brand">Primary site</span>}
                      {/*
                        Said here, beside the button it disables. The server
                        refuses this too, but a refusal arriving after the click
                        — as an alert at the top of a page you are scrolled past
                        — is a reason nobody reads.
                      */}
                      {site.siteType && (
                        <span className="text-white/60 capitalize">{site.siteType}</span>
                      )}
                      {site.jobCount > 0 && (
                        <span className="text-white/60">
                          {site.jobCount} {site.jobCount === 1 ? 'job runs' : 'jobs run'} here
                        </span>
                      )}
                    </span>
                  </span>
                  <span className="flex shrink-0 items-center gap-1">
                    {/*
                      Correcting a site rewrites it on every job standing on
                      it, so a typo is fixed everywhere rather than only here.
                    */}
                    <IconButton
                      icon={PencilLine}
                      label={`Edit ${site.display}`}
                      variant="white"
                      size="sm"
                      onClick={() => setEditingSite(site)}
                    />
                    <IconButton
                      icon={Trash2}
                      label={
                        site.jobCount > 0
                          ? `${site.display} has work on it and cannot be removed`
                          : `Remove ${site.display}`
                      }
                      variant="white"
                      size="sm"
                      disabled={site.jobCount > 0}
                      onClick={() => setRemovingSite(site)}
                      className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
                    />
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>

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

      {/*
        A site a job is standing on is refused server-side — `job_addresses`
        cascades, so the delete would go through and quietly take that job's
        site with it.
      */}
      {editingSite && (
        <EditSiteDialog
          clientId={client.id}
          site={editingSite}
          onClose={() => setEditingSite(null)}
        />
      )}

      <ConfirmDialog
        isOpen={removingSite !== null}
        tone="danger"
        title={`Remove “${removingSite?.display ?? ''}”?`}
        description="It comes off this client's address book. Any job already at it keeps the address it was printed with."
        confirmLabel="Remove site"
        confirmVariant="danger"
        onConfirm={() => {
          if (removingSite) {
            /*
             * No `preserveScroll`: a refusal comes back as a message at the
             * top of the page, and keeping the reader at the address book
             * meant it was never seen.
             */
            router.delete(routeTo.clientAddress(client.id, removingSite.id))
          }
          setRemovingSite(null)
        }}
        onCancel={() => setRemovingSite(null)}
      />
    </PageTransition>
  )
}

interface EditSiteDialogProps {
  clientId: number
  site: ClientSite
  onClose: () => void
}

/**
 * Correcting a site.
 *
 * The change reaches every job standing on it and every project taking it as
 * their address — those keep snapshots rather than reading through the book, so
 * fixing a typo here has to fix it there too, or the work keeps the old
 * spelling for ever.
 *
 * Keyed on the site in the parent, so opening a different one starts a fresh
 * form rather than carrying the last one's edits over.
 */
function EditSiteDialog({ clientId, site, onClose }: EditSiteDialogProps) {
  const form = useForm({
    label: site.label ?? '',
    address: site.address,
    site_type: site.siteType ?? '',
    latitude: site.latitude,
    longitude: site.longitude,
    place_id: site.placeId,
  })

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    form.put(routeTo.clientAddress(clientId, site.id), {
      preserveScroll: true,
      onSuccess: onClose,
    })
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Edit site"
      description={
        site.jobCount > 0
          ? `${site.jobCount} ${site.jobCount === 1 ? 'job runs' : 'jobs run'} here — they will show the corrected address.`
          : undefined
      }
      size="md"
      footer={
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="edit-site" isLoading={form.processing}>
            Save site
          </Button>
        </div>
      }
    >
      <form id="edit-site" onSubmit={submit} className="flex flex-col gap-4">
        <TextInput
          id="edit-site-label"
          label="Name*"
          value={form.data.label}
          onChange={(event) => form.setData('label', toTitleCase(event.target.value))}
          {...(form.errors.label ? { error: form.errors.label } : {})}
        />
        <AddressField
          id="edit-site-address"
          label="Address"
          placeholder="Start typing the site address"
          value={form.data.address}
          latitude={form.data.latitude}
          longitude={form.data.longitude}
          onChange={(place) =>
            form.setData((current) => ({
              ...current,
              address: place.address,
              latitude: place.latitude,
              longitude: place.longitude,
              place_id: place.placeId,
            }))
          }
          {...(form.errors.address ? { error: form.errors.address } : {})}
        />
        {/* The building, not the client — and what a job raised here starts
            from, so correcting it here corrects the next job's default. */}
        <SelectField
          id="edit-site-type"
          label="Site Type"
          options={SITE_TYPE_OPTIONS}
          value={form.data.site_type}
          onChange={(event) => form.setData('site_type', event.target.value)}
          {...(form.errors.site_type ? { error: form.errors.site_type } : {})}
        />
      </form>
    </Modal>
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
