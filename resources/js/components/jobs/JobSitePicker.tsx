import { useEffect, useRef, useState } from 'react'
import { router } from '@inertiajs/react'
import { MapPinPlus, PencilLine, Plus, Save, Trash2, X } from 'lucide-react'
import { AddressField, Button, IconButton, SelectField, TextInput } from '@/components/common'
import { routeTo, SITE_TYPE_OPTIONS } from '@/constants'
import type { ClientAddressOption, JobType } from '@/types'
import { cn } from '@/utils'

export interface JobSitePickerProps {
  /**
   * Whose book this is. Null until a client is chosen — there is nothing to
   * pick from, and nowhere to record a new site, before that.
   *
   * Taken as an id rather than read off a passed-in object: one caller has a
   * client to hand and another only has the project, and a picker that guessed
   * which was which posted new sites to whichever id it was given.
   */
  clientId: number | null
  clientName: string
  /** The sites on that book. */
  sites: readonly ClientAddressOption[]
  /**
   * The picked sites. A job is at one, so its list is of one; `multiple` gives
   * tick boxes for a caller that allows several.
   */
  value: readonly number[]
  onChange: (addressIds: number[]) => void
  multiple?: boolean
  error?: string
  disabled?: boolean
}

/**
 * Which of its client's sites a piece of work runs at.
 *
 * One for a job, so it is radios: picking a second site replaces the first
 * rather than adding to it. A project can span several, and passes `multiple`
 * for tick boxes. Either way the first picked becomes the record's own
 * address, which is what a job sheet and every list show.
 *
 * A site can also be added here. Raising a job for an address the client's
 * record does not have yet is normal, and sending someone to the client screen
 * to record it would lose everything they had typed on this form.
 */
export function JobSitePicker({
  clientId,
  clientName,
  sites,
  value,
  onChange,
  multiple = false,
  error,
  disabled = false,
}: JobSitePickerProps) {
  /*
   * Null when nothing is being written, `'new'` while adding, and a site's id
   * while correcting one. The same three fields either way — a correction is
   * the same form pointed at a row that exists.
   */
  const [editing, setEditing] = useState<number | 'new' | null>(null)
  const [saving, setSaving] = useState(false)
  const [draft, setDraft] = useState({
    label: '',
    address: '',
    // What kind of building it is. The job's own type starts from this, so it
    // is asked while the site is being written rather than guessed afterwards.
    site_type: '' as JobType | '',
    latitude: null as number | null,
    longitude: null as number | null,
    place_id: null as string | null,
  })
  const [addError, setAddError] = useState<string | null>(null)

  /*
   * The address just typed, so the site it becomes can be selected the moment
   * it arrives. A ref rather than state: this drives one call on the next
   * render and nothing about what is drawn, so re-rendering for it would be a
   * render nobody asked for.
   */
  const justAdded = useRef<string | null>(null)

  /*
   * The new site comes back as part of the refreshed props, not in the
   * response — reading it off the response meant knowing which prop the parent
   * kept its clients under, which is not the picker's business.
   */
  useEffect(() => {
    const typed = justAdded.current

    if (typed === null) return

    const added = sites.find((site) => site.address.trim() === typed)

    if (!added) return

    justAdded.current = null

    if (!value.includes(added.id)) {
      onChange(multiple ? [...value, added.id] : [added.id])
    }
  }, [sites, value, onChange, multiple])

  const reset = () => {
    setDraft({
      label: '',
      address: '',
      site_type: '',
      latitude: null,
      longitude: null,
      place_id: null,
    })
    setAddError(null)
    setEditing(null)
  }

  const startEditing = (site: ClientAddressOption) => {
    setDraft({
      label: site.label ?? '',
      address: site.address,
      site_type: site.siteType ?? '',
      latitude: site.latitude ?? null,
      longitude: site.longitude ?? null,
      place_id: site.placeId ?? null,
    })
    setAddError(null)
    setEditing(site.id)
  }

  /** Correcting a site rewrites it on every job standing on it — see the
      controller. Nothing here has to re-pick anything. */
  const saveEdit = (siteId: number) => {
    if (clientId === null || draft.label.trim() === '') {
      setAddError('Name this site.')

      return
    }

    if (draft.address.trim() === '') {
      setAddError('Enter the address.')

      return
    }

    router.put(routeTo.clientAddress(clientId, siteId), draft, {
      preserveScroll: true,
      preserveState: true,
      onStart: () => {
        setSaving(true)
        setAddError(null)
      },
      onSuccess: reset,
      onError: (errors) =>
        setAddError(errors['label'] ?? errors['address'] ?? 'That site could not be saved.'),
      onFinish: () => setSaving(false),
    })
  }

  const removeSite = (siteId: number) => {
    if (clientId === null) return

    router.delete(routeTo.clientAddress(clientId, siteId), {
      preserveScroll: true,
      preserveState: true,
      // Dropped from the picking too — a site that is gone is not chosen.
      onSuccess: () => onChange(value.filter((id) => id !== siteId)),
    })
  }

  /** One site replaces; several toggle, keeping the order they were picked in. */
  const choose = (siteId: number) => {
    if (! multiple) return onChange([siteId])

    onChange(
      value.includes(siteId)
        ? value.filter((id) => id !== siteId)
        : [...value, siteId],
    )
  }

  const addSite = () => {
    if (clientId === null || draft.label.trim() === '') {
      setAddError('Name this site.')

      return
    }

    if (draft.address.trim() === '') {
      setAddError('Enter the address.')

      return
    }

    router.post(routeTo.clientAddresses(clientId), draft, {
      preserveScroll: true,
      preserveState: true,
      onStart: () => {
        setSaving(true)
        setAddError(null)
      },
      onSuccess: () => {
        /*
         * The address that was just typed, remembered so the effect below can
         * select it once the refreshed sites arrive. Reading it off the
         * response meant knowing which prop the parent kept its clients under,
         * which is not the picker's business.
         */
        justAdded.current = draft.address.trim()
        reset()
      },
      onError: (errors) =>
        setAddError(errors['label'] ?? errors['address'] ?? 'That site could not be saved.'),
      onFinish: () => setSaving(false),
    })
  }

  if (clientId === null) {
    return <p className="text-sm text-white/70">Pick a client to see their sites.</p>
  }

  return (
    <div>
      {sites.length === 0 ? (
        <p className="mb-3 text-sm text-white/70">
          {clientName} has no sites on record yet — add the first one below.
        </p>
      ) : (
        <>
          <div className="grid gap-2 sm:grid-cols-2">
            {sites.map((site) => {
              const picked = value.includes(site.id)

              return (
                <label
                  key={site.id}
                  className={cn(
                    'flex cursor-pointer items-start gap-3 rounded-panel border p-3 transition-colors',
                    picked
                      ? 'border-brand/60 bg-brand/8'
                      : 'border-hairline bg-white/4 hover:border-brand/35',
                  )}
                >
                  <input
                    type={multiple ? 'checkbox' : 'radio'}
                    name={multiple ? undefined : `site-${clientId}`}
                    className="mt-0.5 size-4 shrink-0 accent-brand"
                    checked={picked}
                    disabled={disabled}
                    onChange={() => choose(site.id)}
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-md text-white">{site.display}</span>
                    {/* Shown because picking this site sets the job's type. */}
                    {site.siteType && (
                      <span className="block text-xs text-white/60 capitalize">
                        {site.siteType}
                      </span>
                    )}
                  </span>

                  {/*
                    Buttons inside a `<label>` would be swallowed by it, so the
                    click is stopped before the label turns it into a pick.
                  */}
                  <span
                    className="flex shrink-0 items-center gap-1"
                    onClick={(event) => event.preventDefault()}
                  >
                    <IconButton
                      icon={PencilLine}
                      label={`Edit ${site.display}`}
                      variant="white"
                      size="sm"
                      disabled={disabled || saving}
                      onClick={() => startEditing(site)}
                    />
                    <IconButton
                      icon={Trash2}
                      label={`Remove ${site.display}`}
                      variant="white"
                      size="sm"
                      disabled={disabled || saving}
                      onClick={() => removeSite(site.id)}
                      className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
                    />
                  </span>
                </label>
              )
            })}
          </div>
        </>
      )}

      {editing !== null ? (
        <div className="mt-3 rounded-panel border border-hairline bg-white/4 p-4">
          <div className="mb-3 flex items-center justify-between gap-3">
            <p className="text-sm font-medium text-white">
              {editing === 'new' ? `New site for ${clientName}` : 'Edit site'}
            </p>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              leftIcon={X}
              disabled={saving}
              onClick={reset}
            >
              Cancel
            </Button>
          </div>

          <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <TextInput
              id="new-site-label"
              label="Name*"
              placeholder="e.g. Warehouse"
              value={draft.label}
              disabled={saving}
              onChange={(event) => setDraft({ ...draft, label: event.target.value })}
            />
            <AddressField
              id="new-site-address"
              label="Address"
              placeholder="Start typing the site address"
              value={draft.address}
              latitude={draft.latitude}
              longitude={draft.longitude}
              disabled={saving}
              onChange={(place) =>
                setDraft({
                  ...draft,
                  address: place.address,
                  latitude: place.latitude,
                  longitude: place.longitude,
                  place_id: place.placeId,
                })
              }
              {...(addError ? { error: addError } : {})}
            />
          </div>

          <SelectField
            id="new-site-type"
            label="Location Type"
            className="mt-4 lg:max-w-xs"
            options={SITE_TYPE_OPTIONS}
            value={draft.site_type}
            disabled={saving}
            onChange={(event) =>
              setDraft({ ...draft, site_type: event.target.value as JobType | '' })
            }
          />

          <Button
            type="button"
            size="sm"
            className="mt-4"
            leftIcon={editing === 'new' ? Plus : Save}
            isLoading={saving}
            onClick={() => (editing === 'new' ? addSite() : saveEdit(editing))}
          >
            {editing === 'new' ? 'Add location' : 'Save site'}
          </Button>
        </div>
      ) : (
        <Button
          type="button"
          variant="white"
          size="sm"
          className="mt-3"
          leftIcon={MapPinPlus}
          disabled={disabled}
          onClick={() => setEditing('new')}
        >
          Add location
        </Button>
      )}

      {error && <p className="mt-2 text-sm text-red-300">{error}</p>}
    </div>
  )
}
