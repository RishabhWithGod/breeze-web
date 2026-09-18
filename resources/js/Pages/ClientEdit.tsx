import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, DollarSign, Save } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  TextArea,
  TextInput,
} from '@/components/common'
import { ClientSitesList, type ClientSite } from '@/components/clients'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { cleanAmountInput, formatAmountInput, toTitleCase } from '@/utils'

interface ClientEditForm {
  name: string
  notes: string
  labor_rate: string
}

export interface ClientEditProps {
  client: {
    readonly id: number
    readonly name: string
    readonly notes: string | null
    /** Resolved — the client's own rate, or the configured default. */
    readonly laborRate: number
    readonly addresses: readonly ClientSite[]
  }
}

/**
 * Edit a client's own details, and correct any of their sites in place.
 *
 * Editing a site here updates that same record — see
 * `ClientAddressController::update()` — rather than raising a new one, and the
 * correction reaches every job and project already standing on it.
 */
export default function ClientEdit({ client }: ClientEditProps) {
  const { data, setData, put, processing, errors, hasErrors, clearErrors } =
    useForm<ClientEditForm>({
      name: client.name,
      notes: client.notes ?? '',
      labor_rate: String(client.laborRate),
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

            {/*
              Each site saves itself the moment its own dialog is confirmed —
              see `ClientAddressController::update()` — rather than waiting on
              "Save changes" below, which only ever covers the fields above.
            */}
            <div className="border-t border-hairline pt-6">
              <p className="mb-4 text-md font-medium text-white">Site Location(s)</p>

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
