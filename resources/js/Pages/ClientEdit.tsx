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
  }
}

/**
 * Edit a client's own details.
 *
 * The address book is not here: sites are added from wherever they are needed
 * — the client's screen, a project, a job — and editing them through a form
 * that has to be saved would be a second, slower way to do the same thing.
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
