import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, ArrowRight, CloudUpload, Mail, Phone, Save } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { AddressField, Button, ButtonLink, Checkbox, TextInput } from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import type { SelectOption } from '@/types'
import { cn, formatUsPhone } from '@/utils'

interface CompanyProfileData {
  readonly name: string
  readonly businessAddress: string
  readonly primaryContact: string
  readonly phone: string
  readonly email: string
  readonly licenseNumber: string | null
  readonly timezone: string
  readonly logoUrl: string | null
}

export interface CompanySetupProps {
  /** This account's company as it stands, once it has been set up. */
  company: CompanyProfileData | null
  /** What a blank form starts with: the person signing up. */
  defaults: { readonly primaryContact: string; readonly email: string }
  timezones: readonly SelectOption[]
  /** Set when the owner is correcting a company that is already set up. */
  editing?: { readonly saveUrl: string; readonly backUrl: string }
}

interface CompanyDraft {
  name: string
  business_address: string
  primary_contact: string
  phone: string
  email: string
  license_number: string
  timezone: string
  logo: File | null
  /** Only when correcting: take the current logo away. */
  remove_logo: boolean
}

const MAX_LOGO_BYTES = 2 * 1024 * 1024

/** The browser's own zone, when it is one the server will accept. */
function browserTimezone(zones: readonly SelectOption[]): string {
  if (typeof Intl === 'undefined') return ''
  const zone = Intl.DateTimeFormat().resolvedOptions().timeZone

  return zones.some((option) => option.value === zone) ? zone : ''
}

/**
 * Company Profile — the first thing a new account fills in.
 *
 * Until it is saved every other screen sends the person back here, so the
 * company is named before any work is done under it.
 */
export default function CompanySetup({ company, defaults, timezones, editing }: CompanySetupProps) {
  const fileInput = useRef<HTMLInputElement>(null)
  const [logoError, setLogoError] = useState<string | null>(null)
  const [addressPoint, setAddressPoint] = useState<{ latitude: number | null; longitude: number | null }>({
    latitude: null,
    longitude: null,
  })

  const { data, setData, post, transform, processing, errors, clearErrors } = useForm<CompanyDraft>({
    name: company?.name ?? '',
    business_address: company?.businessAddress ?? '',
    primary_contact: company?.primaryContact ?? defaults.primaryContact,
    phone: company?.phone ?? '',
    email: company?.email ?? defaults.email,
    license_number: company?.licenseNumber ?? '',
    timezone: company?.timezone ?? browserTimezone(timezones),
    logo: null,
    remove_logo: false,
  })

  // A chosen file is previewed from a temporary URL, which has to be let go.
  const chosenLogoUrl = useMemo(() => (data.logo ? URL.createObjectURL(data.logo) : null), [data.logo])
  useEffect(() => (chosenLogoUrl ? () => URL.revokeObjectURL(chosenLogoUrl) : undefined), [chosenLogoUrl])
  const preview = chosenLogoUrl ?? (data.remove_logo ? null : (company?.logoUrl ?? null))

  /** Each edit clears its own server message, so a fixed field stops complaining. */
  const update = <K extends FormDataKeys<CompanyDraft>>(field: K, value: FormDataValues<CompanyDraft, K>) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const chooseLogo = (file: File | undefined) => {
    if (!file) return
    if (file.size > MAX_LOGO_BYTES) {
      setLogoError('The logo must be 2 MB or smaller.')
      return
    }
    setLogoError(null)
    update('logo', file)
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    if (editing) {
      // A file cannot travel in a PUT, so it is posted as one.
      transform((form) => ({ ...form, _method: 'put' }))
      post(editing.saveUrl, { forceFormData: true, preserveScroll: true })

      return
    }

    post('/company-setup', { forceFormData: true, preserveScroll: true })
  }

  return (
    <PageTransition>
      <Head title={editing ? 'Edit Company Profile' : 'Company Profile'} />

      <form
        onSubmit={submit}
        noValidate
        className="mx-auto max-w-5xl rounded-card border border-l-4 border-brand/40 border-l-brand glass p-6 shadow-glow sm:p-9"
      >
        <h1 className="text-4xl font-bold text-white">{editing ? 'Edit Company Profile' : 'Company Profile'}</h1>
        <p className="mt-2 text-md text-white/85">
          {editing
            ? 'Correct your company details. They are used across your account.'
            : "Let’s get your company set up. This information will be used across your account."}
        </p>

        <div className="mt-8 grid gap-5 md:grid-cols-2">
          <FieldCard>
            <TextInput
              id="company-name"
              label="Company Name"
              value={data.name}
              maxLength={255}
              autoFocus
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
            />
          </FieldCard>

          <FieldCard>
            <AddressField
              id="business-address"
              label="Business Address"
              placeholder="Start typing the business address"
              value={data.business_address}
              latitude={addressPoint.latitude}
              longitude={addressPoint.longitude}
              onChange={(place) => {
                // Only the address text is saved on the company; the point just
                // lets the field say "Location selected" for a chosen suggestion.
                setAddressPoint({ latitude: place.latitude, longitude: place.longitude })
                update('business_address', place.address)
              }}
              {...(errors.business_address ? { error: errors.business_address } : {})}
            />
          </FieldCard>

          <FieldCard>
            <TextInput
              id="primary-contact"
              label="Primary Contact"
              value={data.primary_contact}
              maxLength={255}
              onChange={(event) => update('primary_contact', event.target.value)}
              {...(errors.primary_contact ? { error: errors.primary_contact } : {})}
            />
          </FieldCard>

          <FieldCard>
            <TextInput
              id="company-phone"
              label="Phone"
              type="tel"
              inputMode="tel"
              leftIcon={Phone}
              value={data.phone}
              onChange={(event) => update('phone', formatUsPhone(event.target.value))}
              {...(errors.phone ? { error: errors.phone } : {})}
            />
          </FieldCard>

          <FieldCard>
            <TextInput
              id="company-email"
              label="Email"
              type="email"
              leftIcon={Mail}
              value={data.email}
              maxLength={255}
              onChange={(event) => update('email', event.target.value)}
              {...(errors.email ? { error: errors.email } : {})}
            />
          </FieldCard>

          <FieldCard>
            <TextInput
              id="license-number"
              label="License Number"
              value={data.license_number}
              maxLength={100}
              onChange={(event) => update('license_number', event.target.value)}
              {...(errors.license_number ? { error: errors.license_number } : {})}
            />
          </FieldCard>

          <FieldCard>
            <p className="mb-2 text-md font-medium text-white">Company Logo</p>
            <input
              ref={fileInput}
              type="file"
              accept="image/png,image/jpeg,image/webp"
              className="sr-only"
              aria-label="Company logo"
              onChange={(event) => chooseLogo(event.target.files?.[0])}
            />
            <button
              type="button"
              onClick={() => fileInput.current?.click()}
              onDragOver={(event) => event.preventDefault()}
              onDrop={(event) => {
                event.preventDefault()
                chooseLogo(event.dataTransfer.files[0])
              }}
              className={cn(
                'grid h-36 w-full place-items-center rounded-panel border border-dashed border-white/50 bg-white/5 transition-colors',
                'hover:border-brand hover:bg-white/8 focus-visible:border-brand focus-visible:outline-none',
              )}
            >
              {preview ? (
                <img src={preview} alt="Company logo preview" className="max-h-28 max-w-[80%] object-contain" />
              ) : (
                <span className="flex flex-col items-center gap-2 text-sm text-white/75">
                  <CloudUpload size={44} strokeWidth={1.4} aria-hidden className="text-white/85" />
                  PNG, JPG or WebP · up to 2 MB
                </span>
              )}
            </button>
            {(logoError ?? errors.logo) && <p className="mt-2 text-sm text-red-300">{logoError ?? errors.logo}</p>}
            {editing && company?.logoUrl && !data.logo && (
              <Checkbox
                id="remove-logo"
                className="mt-3"
                label="Remove the current logo"
                checked={data.remove_logo}
                onChange={(event) => setData('remove_logo', event.target.checked)}
              />
            )}
          </FieldCard>
        </div>

        <div className={editing ? 'mt-8 flex items-center justify-between gap-4' : 'mt-8 flex justify-end'}>
          {editing && (
            <ButtonLink href={editing.backUrl} variant="secondary" size="lg" leftIcon={ArrowLeft}>
              Cancel
            </ButtonLink>
          )}
          <Button
            type="submit"
            size="lg"
            {...(editing ? { leftIcon: Save } : { rightIcon: ArrowRight })}
            isLoading={processing}
          >
            {editing ? 'Save Changes' : 'Save and Continue'}
          </Button>
        </div>
      </form>
    </PageTransition>
  )
}

CompanySetup.layout = appLayout

/** One field in its own box, the way the design lays the form out. */
function FieldCard({ children }: { children: React.ReactNode }) {
  return <div className="rounded-card border border-hairline bg-white/4 p-5">{children}</div>
}
