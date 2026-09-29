import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, DollarSign, Globe, Mail, Phone, Save } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  TextArea,
  TextInput,
} from '@/components/common'
import { ClientContactsList, ClientSitesList, type ClientContact, type ClientSite } from '@/components/clients'
import { TeamPicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { cleanAmountInput, formatAmountInput, formatUsPhone, toTitleCase } from '@/utils'

interface ClientEditForm {
  name: string
  website: string
  contact_email: string
  contact_phone: string
  notes: string
  labor_rate: string
  team_id: string
}

export interface ClientEditProps {
  client: {
    readonly id: number
    readonly name: string
    readonly website: string | null
    /** The primary contact's own email/phone — a quick way in without opening the Contacts card. */
    readonly contactEmail: string | null
    readonly contactPhone: string | null
    readonly notes: string | null
    /** Resolved — the client's own rate, or the configured default. */
    readonly laborRate: number
    /** The crew this client's projects are normally staffed from, when one has been picked. */
    readonly teamId: number | null
    readonly contacts: readonly ClientContact[]
    readonly addresses: readonly ClientSite[]
  }
  /** The crew register, for the "which team works this client's sites" picker. */
  teams: readonly { readonly id: number; readonly name: string }[]
}

/**
 * Edit a client's own details, and correct any of their sites in place.
 *
 * Editing a site here updates that same record — see
 * `ClientAddressController::update()` — rather than raising a new one, and the
 * correction reaches every job and project already standing on it.
 */
export default function ClientEdit({ client, teams }: ClientEditProps) {
  const { data, setData, put, processing, errors, hasErrors, clearErrors } =
    useForm<ClientEditForm>({
      name: client.name,
      website: client.website ?? '',
      contact_email: client.contactEmail ?? '',
      contact_phone: client.contactPhone ?? '',
      notes: client.notes ?? '',
      labor_rate: String(client.laborRate),
      team_id: client.teamId === null ? '' : String(client.teamId),
    })

  const update = <K extends FormDataKeys<ClientEditForm>>(
    field: K,
    value: FormDataValues<ClientEditForm, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    put(routeTo.client(client.id))
  }

  return (
    <PageTransition>
      <Head title={`Edit ${client.name}`} />

      <PageHeader
        title={`Edit ${client.name}`}
        breadcrumbs={[
          { label: 'Clients', href: ROUTES.clients },
          { label: client.name, href: routeTo.client(client.id) },
          { label: 'Edit' },
        ]}
        actions={
          <ButtonLink
            href={routeTo.client(client.id)}
            variant="secondary"
            leftIcon={ArrowLeft}
          >
            Back
          </ButtonLink>
        }
      />

      <form onSubmit={submit} noValidate className="space-y-6">
        <AnimatePresence initial={false}>
          {hasErrors && (
            <Alert key="form-error" tone="danger" title="Check the form">
              Some fields need attention before these changes can be saved.
            </Alert>
          )}
        </AnimatePresence>

        <Card padding="lg">
          <CardHeader title="Client details" />

          <div className="space-y-6">
            <TextInput
              id="client-name"
              label="Client Name*"
              autoComplete="off"
              value={data.name}
              onChange={(event) => update('name', toTitleCase(event.target.value))}
              {...(errors.name ? { error: errors.name } : {})}
            />

            <div className="grid gap-6 sm:grid-cols-2">
              <TextInput
                id="client-contact-email"
                type="email"
                leftIcon={Mail}
                label="Contact Email"
                placeholder="e.g. dana@example.com"
                hint="Sets their primary contact. Add or edit anyone else under Contacts, below."
                autoComplete="off"
                value={data.contact_email}
                onChange={(event) => update('contact_email', event.target.value)}
                {...(errors.contact_email ? { error: errors.contact_email } : {})}
              />

              <TextInput
                id="client-contact-phone"
                type="tel"
                inputMode="tel"
                leftIcon={Phone}
                label="Contact Phone"
                placeholder="(415) 555-0134"
                autoComplete="off"
                value={data.contact_phone}
                onChange={(event) => update('contact_phone', formatUsPhone(event.target.value))}
                {...(errors.contact_phone ? { error: errors.contact_phone } : {})}
              />
            </div>

            <div className="grid gap-6 sm:grid-cols-2">
              <TextInput
                id="client-labor-rate"
                type="text"
                inputMode="decimal"
                leftIcon={DollarSign}
                label="Labor Rate ($/hr)"
                placeholder="e.g. 50"
                hint="What an hour of this client's labor is billed at. Every estimate on their projects prices labor at this rate."
                value={formatAmountInput(data.labor_rate)}
                onChange={(event) => update('labor_rate', cleanAmountInput(event.target.value))}
                {...(errors.labor_rate ? { error: errors.labor_rate } : {})}
              />

              <TextInput
                id="client-website"
                type="text"
                leftIcon={Globe}
                label="Website"
                placeholder="e.g. www.coldbar.com"
                autoComplete="off"
                value={data.website}
                onChange={(event) => update('website', event.target.value)}
                {...(errors.website ? { error: errors.website } : {})}
              />
            </div>

            <TeamPicker
              teams={teams}
              value={data.team_id}
              onChange={(next) => update('team_id', next)}
              hint="Who normally works this client's sites. Projects raised for them can be staffed from this crew."
              disabled={processing}
              {...(errors.team_id ? { error: errors.team_id } : {})}
            />

            {/*
              Each contact and each site saves itself the moment its own
              dialog is confirmed, rather than waiting on "Save changes"
              below, which only ever covers the fields above.
            */}
            <div className="border-t border-hairline pt-6">
              <p className="mb-4 text-md font-medium text-white">Contacts</p>

              <ClientContactsList clientId={client.id} contacts={client.contacts} />
            </div>

            <div className="border-t border-hairline pt-6">
              <p className="mb-4 text-md font-medium text-white">Addresses</p>

              <ClientSitesList clientId={client.id} addresses={client.addresses} />
            </div>

            <TextArea
              id="client-notes"
              label="Notes"
              rows={4}
              maxLength={2000}
              placeholder="Anything worth knowing about this client."
              value={data.notes}
              onChange={(event) => update('notes', event.target.value)}
              addon={`${data.notes.length}/2000`}
              {...(errors.notes ? { error: errors.notes } : {})}
            />
          </div>
        </Card>

        <div className="flex flex-wrap items-center justify-end gap-3">
          <ButtonLink href={routeTo.client(client.id)} variant="white">
            Cancel
          </ButtonLink>
          <Button type="submit" leftIcon={Save} isLoading={processing}>
            Save changes
          </Button>
        </div>
      </form>
    </PageTransition>
  )
}

ClientEdit.layout = appLayout
