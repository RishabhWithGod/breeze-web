import { useState } from 'react'
import { router } from '@inertiajs/react'
import { MapPinPlus, Plus, X } from 'lucide-react'
import { AddressField, Button, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { ClientOption } from '@/types'
import { cn } from '@/utils'

export interface JobSitePickerProps {
  /** Null until a client is chosen — there is nothing to pick from before that. */
  client: ClientOption | undefined
  /** Picked site ids, in the order they were ticked. The first is the job's address. */
  value: readonly number[]
  onChange: (addressIds: number[]) => void
  error?: string
  disabled?: boolean
}

/**
 * Which of its client's sites a job runs at.
 *
 * A set, not a field: a fit-out over two buildings is one job at two addresses.
 * The order of ticking matters — the first becomes the job's own address, which
 * is what a job sheet and every list show.
 *
 * A site can also be added here. Raising a job for an address the client's
 * record does not have yet is normal, and sending someone to the client screen
 * to record it would lose everything they had typed on this form.
 */
export function JobSitePicker({
  client,
  value,
  onChange,
  error,
  disabled = false,
}: JobSitePickerProps) {
  const [isAdding, setIsAdding] = useState(false)
  const [saving, setSaving] = useState(false)
  const [draft, setDraft] = useState({
    label: '',
    address: '',
    latitude: null as number | null,
    longitude: null as number | null,
  })
  const [addError, setAddError] = useState<string | null>(null)

  const reset = () => {
    setDraft({ label: '', address: '', latitude: null, longitude: null })
    setAddError(null)
    setIsAdding(false)
  }

  const toggle = (siteId: number) => {
    onChange(
      value.includes(siteId)
        ? value.filter((id) => id !== siteId)
        : [...value, siteId],
    )
  }

  const addSite = () => {
    if (!client || draft.address.trim() === '') {
      setAddError('Enter the address.')
      return
    }

    const known = new Set(client.addresses.map((site) => site.id))

    router.post(routeTo.clientAddresses(client.id), draft, {
      preserveScroll: true,
      preserveState: true,
      onStart: () => {
        setSaving(true)
        setAddError(null)
      },
      onSuccess: (page) => {
        /*
         * The visit brings back a refreshed client list. Ticking the site that
         * was not there before saves the person picking the address they have
         * just this second typed out.
         */
        const clients = (page.props['clients'] ?? []) as readonly ClientOption[]
        const refreshed = clients.find((option) => option.id === client.id)
        const added = refreshed?.addresses.find((site) => !known.has(site.id))

        if (added) onChange([...value, added.id])
        reset()
      },
      onError: (errors) => setAddError(errors['address'] ?? 'That address could not be saved.'),
      onFinish: () => setSaving(false),
    })
  }

  if (!client) {
    return <p className="text-sm text-white/70">Pick a client to see their sites.</p>
  }

  return (
    <div>
      {client.addresses.length === 0 ? (
        <p className="mb-3 text-sm text-white/70">
          {client.name} has no sites on record yet — add the first one below.
        </p>
      ) : (
        <>
          <p className="mb-3 text-sm text-white/70">
            Tick every site this job runs at — the first is the job&apos;s own address.
          </p>
          <div className="grid gap-2 sm:grid-cols-2">
            {client.addresses.map((site) => {
              const pickedAt = value.indexOf(site.id)

              return (
                <label
                  key={site.id}
                  className={cn(
                    'flex cursor-pointer items-start gap-3 rounded-panel border p-3 transition-colors',
                    pickedAt >= 0
                      ? 'border-brand/60 bg-brand/8'
                      : 'border-hairline bg-white/4 hover:border-brand/35',
                  )}
                >
                  <input
                    type="checkbox"
                    className="mt-0.5 size-4 shrink-0 accent-brand"
                    checked={pickedAt >= 0}
                    disabled={disabled}
                    onChange={() => toggle(site.id)}
                  />
                  <span className="min-w-0">
                    <span className="block truncate text-md text-white">{site.display}</span>
                    {pickedAt === 0 && <span className="text-sm text-brand">Job address</span>}
                  </span>
                </label>
              )
            })}
          </div>
        </>
      )}

      {isAdding ? (
        <div className="mt-3 rounded-panel border border-hairline bg-white/4 p-4">
          <div className="mb-3 flex items-center justify-between gap-3">
            <p className="text-sm font-medium text-white">New site for {client.name}</p>
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
              label="Name (optional)"
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
              onChange={(address, latitude, longitude) =>
                setDraft({ ...draft, address, latitude, longitude })
              }
              {...(addError ? { error: addError } : {})}
            />
          </div>

          <Button
            type="button"
            size="sm"
            className="mt-4"
            leftIcon={Plus}
            isLoading={saving}
            onClick={addSite}
          >
            Add site
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
          onClick={() => setIsAdding(true)}
        >
          Add a site
        </Button>
      )}

      {error && <p className="mt-2 text-sm text-red-300">{error}</p>}
    </div>
  )
}
