import { useState } from 'react'
import { router, useForm } from '@inertiajs/react'
import { MapPin, PencilLine, Trash2 } from 'lucide-react'
import {
  AddressField,
  Button,
  Card,
  cardAccent,
  CardHeader,
  ConfirmDialog,
  IconButton,
  Modal,
  SelectField,
  TextInput,
} from '@/components/common'
import { routeTo, SITE_TYPE_OPTIONS } from '@/constants'
import { toTitleCase } from '@/utils'

export interface ClientSite {
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

export interface ClientSitesListProps {
  clientId: number
  addresses: readonly ClientSite[]
}

/**
 * A client's address book — one place to see and correct every site on
 * record, shared by the client's own screen and its edit form.
 *
 * Correcting a site rewrites it on every job and project standing on it (see
 * `ClientAddressController::update()`), so a typo is fixed everywhere rather
 * than only here, and saving never creates a second record for the same site.
 *
 * The bare list, with no card of its own — so it can sit inside whatever
 * wrapper the screen it's on already uses (`ClientSitesCard` below for a
 * standalone card, or straight inside another card's own layout).
 */
export function ClientSitesList({ clientId, addresses }: ClientSitesListProps) {
  const [removingSite, setRemovingSite] = useState<ClientSite | null>(null)
  const [editingSite, setEditingSite] = useState<ClientSite | null>(null)

  return (
    <>
      {addresses.length === 0 ? (
        <p className="text-md text-white/70">
          No sites on record yet. One is added the first time a project needs it.
        </p>
      ) : (
        <ul className="space-y-2">
          {addresses.map((site) => (
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

      {/*
        A site a job is standing on is refused server-side — `job_addresses`
        cascades, so the delete would go through and quietly take that job's
        site with it.
      */}
      {editingSite && (
        <EditSiteDialog
          clientId={clientId}
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
            router.delete(routeTo.clientAddress(clientId, removingSite.id))
          }
          setRemovingSite(null)
        }}
        onCancel={() => setRemovingSite(null)}
      />
    </>
  )
}

export interface ClientSitesCardProps {
  clientId: number
  addresses: readonly ClientSite[]
}

/** `ClientSitesList` in its own standalone card — the client's own screen. */
export function ClientSitesCard({ clientId, addresses }: ClientSitesCardProps) {
  return (
    <Card padding="lg" className={cardAccent('success', 'min-w-0')}>
      <CardHeader
        title="Site Location(s)"
        subtitle="One book, shared by every project of theirs"
      />
      <ClientSitesList clientId={clientId} addresses={addresses} />
    </Card>
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
          label="Location Type"
          options={SITE_TYPE_OPTIONS}
          value={form.data.site_type}
          onChange={(event) => form.setData('site_type', event.target.value)}
          {...(form.errors.site_type ? { error: form.errors.site_type } : {})}
        />
      </form>
    </Modal>
  )
}
