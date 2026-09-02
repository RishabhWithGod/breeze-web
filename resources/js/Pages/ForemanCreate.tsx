import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, HardHat } from 'lucide-react'
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
import { ROUTES } from '@/constants'

interface ForemanDraft {
  name: string
  phone: string
  email: string
  licence_number: string
  started_on: string
  notes: string
}

/**
 * Add Foreman.
 *
 * The name is the only thing required: a foreman exists to be handed work, and
 * nothing else is needed to do that. The rest is what you reach for once they
 * have it — a number to call, a licence to quote — so it is on this screen,
 * optional, rather than on a second one nobody would come back to.
 *
 * Initials are not asked for at all. "Dana Wu" gives "DW", and a field the app
 * can fill in itself is one more thing to type and one more thing to get wrong.
 */
export default function ForemanCreate() {
  const { data, setData, post, processing, errors, hasErrors, clearErrors } =
    useForm<ForemanDraft>({
      name: '',
      phone: '',
      email: '',
      licence_number: '',
      started_on: '',
      notes: '',
    })

  /**
   * Inertia keeps server errors until the next request, which would leave
   * "Enter the foreman's name" sitting under a field the user has just filled
   * in, so each edit clears its own message.
   */
  const update = <K extends FormDataKeys<ForemanDraft>>(
    field: K,
    value: FormDataValues<ForemanDraft, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    post(ROUTES.foremen)
  }

  return (
    <PageTransition>
      <Head title="Add Foreman" />

      <PageHeader
        title="Add Foreman"
        subtitle="Someone who runs work on site and can be handed tasks."
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Foremen', href: ROUTES.foremen },
          { label: 'New Foreman' },
        ]}
        actions={
          <ButtonLink href={ROUTES.foremen} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      <form onSubmit={submit} noValidate className="space-y-6">
        <AnimatePresence initial={false}>
          {hasErrors && (
            <Alert key="form-error" tone="danger" title="Check the form">
              Some fields need attention before this foreman can be added.
            </Alert>
          )}
        </AnimatePresence>

        <Card padding="lg">
          <CardHeader
            title="Foreman details"
            subtitle="Who they are, and how the office reaches them"
          />

          <div className="space-y-6">
            <TextInput
              id="foreman-name"
              label="Foreman Name*"
              placeholder="e.g. Dana Wu"
              autoComplete="off"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
            />

            <div className="grid gap-6 sm:grid-cols-2">
              <TextInput
                id="foreman-started"
                label="Date of Joining"
                type="date"
                value={data.started_on}
                onChange={(event) => update('started_on', event.target.value)}
                {...(errors.started_on ? { error: errors.started_on } : {})}
              />

              <TextInput
                id="foreman-licence"
                label="Licence Number"
                placeholder="e.g. EC-4471"
                autoComplete="off"
                hint="What lets them sign off work on site."
                value={data.licence_number}
                onChange={(event) => update('licence_number', event.target.value)}
                {...(errors.licence_number ? { error: errors.licence_number } : {})}
              />
            </div>

            <div className="grid gap-6 sm:grid-cols-2">
              <TextInput
                id="foreman-phone"
                label="Phone"
                type="tel"
                placeholder="e.g. (415) 555-0134"
                autoComplete="off"
                value={data.phone}
                onChange={(event) => update('phone', event.target.value)}
                {...(errors.phone ? { error: errors.phone } : {})}
              />

              <TextInput
                id="foreman-email"
                label="Email"
                type="email"
                placeholder="e.g. dana@example.com"
                autoComplete="off"
                value={data.email}
                onChange={(event) => update('email', event.target.value)}
                {...(errors.email ? { error: errors.email } : {})}
              />
            </div>

            <TextArea
              id="foreman-notes"
              label="Notes"
              rows={4}
              maxLength={2000}
              placeholder="Certifications, what they specialise in, who to call instead."
              value={data.notes}
              onChange={(event) => update('notes', event.target.value)}
              addon={`${data.notes.length}/2000`}
              {...(errors.notes ? { error: errors.notes } : {})}
            />
          </div>
        </Card>

        <div className="flex flex-wrap items-center justify-end gap-3">
          <ButtonLink href={ROUTES.foremen} variant="white">
            Cancel
          </ButtonLink>
          <Button type="submit" leftIcon={HardHat} isLoading={processing}>
            Add Foreman
          </Button>
        </div>
      </form>
    </PageTransition>
  )
}

ForemanCreate.layout = appLayout
