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
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import { formatUsPhone } from '@/utils'

interface ForemanDraft {
  name: string
  /** What they do on the crew: `supervisor` or `foreman`. */
  role: string
  /** Which crew they are on. Empty means none — a real state, not a gap. */
  team_id: string
  phone: string
  email: string
  licence_number: string
  started_on: string
  notes: string
}

export interface ForemanCreateProps {
  /** The crews someone can be put on. Empty until the first team is added. */
  teams: readonly { readonly id: number; readonly name: string }[]
  roles: readonly { readonly value: string; readonly label: string }[]
}

/**
 * Add Member.
 *
 * A name and a role are what is required: the register exists to say who runs
 * work and who supervises it, and neither question has a sensible default. The
 * rest is what you reach for once they are on it — a number to call, a licence
 * to quote — so it is on this screen, optional, rather than on a second one
 * nobody would come back to.
 *
 * Initials are not asked for at all. "Dana Wu" gives "DW", and a field the app
 * can fill in itself is one more thing to type and one more thing to get wrong.
 */
export default function ForemanCreate({ teams, roles }: ForemanCreateProps) {
  const { data, setData, post, processing, errors, hasErrors, clearErrors } =
    useForm<ForemanDraft>({
      name: '',
      // Most of the register is foremen; a supervisor is the exception you pick.
      role: 'foreman',
      team_id: '',
      phone: '',
      email: '',
      licence_number: '',
      started_on: '',
      notes: '',
    })

  /**
   * Inertia keeps server errors until the next request, which would leave
   * "Enter the member's name" sitting under a field the user has just filled
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
      <Head title="Add Member" />

      <PageHeader
        title="Add Member"
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Teams', href: ROUTES.teams },
          { label: 'New Member' },
        ]}
        actions={
          <ButtonLink href={ROUTES.teams} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      <form onSubmit={submit} noValidate className="space-y-6">
        <AnimatePresence initial={false}>
          {hasErrors && (
            <Alert key="form-error" tone="danger" title="Check the form">
              Some fields need attention before this member can be added.
            </Alert>
          )}
        </AnimatePresence>

        <Card padding="lg">
          <CardHeader title="Member details" />

          <div className="space-y-6">
            <TextInput
              id="foreman-name"
              label="Member Name*"
              placeholder="e.g. Dana Wu"
              autoComplete="off"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
            />

            <div className="grid gap-6 sm:grid-cols-2">
              {/*
                Asked before anything else about them, because it is what the
                register is for: who supervises, and who runs the work.
              */}
              <SelectField
                id="foreman-role"
                label="Role*"
                options={roles.map((role) => ({ label: role.label, value: role.value }))}
                value={data.role}
                onChange={(event) => update('role', event.target.value)}
                {...(errors.role ? { error: errors.role } : {})}
              />

              {/*
                Optional, and blank is a real answer: somebody can be hired
                before their crew is decided, and the register lists them under
                "Not on a team" rather than inventing one.
              */}
              <SelectField
                id="foreman-team"
                label="Team"
                options={[
                  { label: teams.length > 0 ? 'Not on a team' : 'No teams yet', value: '' },
                  ...teams.map((team) => ({ label: team.name, value: String(team.id) })),
                ]}
                value={data.team_id}
                onChange={(event) => update('team_id', event.target.value)}
                {...(errors.team_id ? { error: errors.team_id } : {})}
              />
            </div>

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
                value={data.licence_number}
                onChange={(event) => update('licence_number', event.target.value)}
                {...(errors.licence_number ? { error: errors.licence_number } : {})}
              />
            </div>

            <div className="grid gap-6 sm:grid-cols-2">
              {/*
                Shaped as it is typed, so the field shows what will be stored
                rather than correcting it after the fact. Whether the number is
                a real one is still the server's answer — see UsPhoneNumber.
              */}
              <TextInput
                id="foreman-phone"
                label="Phone"
                type="tel"
                inputMode="tel"
                placeholder="(415) 555-0134"
                autoComplete="off"
                value={data.phone}
                onChange={(event) => update('phone', formatUsPhone(event.target.value))}
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
          <ButtonLink href={ROUTES.teams} variant="white">
            Cancel
          </ButtonLink>
          <Button type="submit" leftIcon={HardHat} isLoading={processing}>
            Add Member
          </Button>
        </div>
      </form>
    </PageTransition>
  )
}

ForemanCreate.layout = appLayout
