import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, Save } from 'lucide-react'
import { Alert, Button, ButtonLink, Card, CardHeader, SelectField, TextArea, TextInput } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { formatUsPhone, toTitleCase } from '@/utils'

interface ForemanDraft {
  name: string
  /** What they do on the crew: `foreman`, `journeyman` or `apprentice`. */
  role: string
  /** Which crew they are on. Empty means none — a real state, not a gap. */
  team_id: string
  phone: string
  email: string
  licence_number: string
  started_on: string
  notes: string
}

export interface ForemanEditProps {
  /** The crews someone can be put on. Empty until the first team is added. */
  teams: readonly { readonly id: number; readonly name: string }[]
  roles: readonly { readonly value: string; readonly label: string }[]
  foreman: {
    readonly id: number
    readonly name: string
    readonly initials: string
    readonly role: string
    readonly teamId: number | null
    readonly phone: string | null
    readonly email: string | null
    readonly licenceNumber: string | null
    readonly joinedOn: string | null
    readonly notes: string | null
  }
  /** Set when the person being edited is a manager rather than crew. */
  manager?: {
    readonly saveUrl: string
    readonly backUrl: string
    /** False for someone's own sign-in email — that is changed from Security. */
    readonly canEditEmail: boolean
  }
}

/**
 * Edit Member.
 *
 * A name and a role are what is required: the register exists to say who runs
 * work and who supervises it. The rest is what you reach for once they
 * have it — a number to call, a licence to quote — so it is on this screen,
 * optional, rather than on a second one nobody would come back to.
 *
 * Initials are not asked for at all. "Dana Wu" gives "DW", and a field the app
 * can fill in itself is one more thing to type and one more thing to get wrong
 * — a rename re-derives them rather than leaving the old ones behind.
 */
export default function ForemanEdit({ foreman, teams, roles, manager }: ForemanEditProps) {
  const isManager = manager !== undefined
  const backUrl = manager?.backUrl ?? routeTo.foreman(foreman.id)

  const { data, setData, put, transform, processing, errors, hasErrors, clearErrors } = useForm<ForemanDraft>({
    name: foreman.name,
    role: foreman.role,
    team_id: foreman.teamId === null ? '' : String(foreman.teamId),
    phone: foreman.phone ?? '',
    email: foreman.email ?? '',
    licence_number: foreman.licenceNumber ?? '',
    started_on: foreman.joinedOn ?? '',
    notes: foreman.notes ?? '',
  })

  /**
   * Inertia keeps server errors until the next request, which would leave
   * "Enter the foreman's name" sitting under a field the user has just filled
   * in, so each edit clears its own message.
   */
  const update = <K extends FormDataKeys<ForemanDraft>>(field: K, value: FormDataValues<ForemanDraft, K>) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    if (manager) {
      // A manager has a name, a phone and a sign-in email; the crew fields do not apply.
      transform((form) => ({
        name: form.name,
        phone: form.phone,
        ...(manager.canEditEmail ? { email: form.email } : {}),
      }))
      put(manager.saveUrl)

      return
    }

    put(routeTo.foreman(foreman.id))
  }

  return (
    <PageTransition>
      <Head title={`Edit ${foreman.name}`} />

      <PageHeader
        title={`Edit ${foreman.name}`}
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Teams', href: ROUTES.teams },
          isManager ? { label: 'Managers', href: backUrl } : { label: foreman.name, href: routeTo.foreman(foreman.id) },
          { label: 'Edit' },
        ]}
        actions={
          <ButtonLink href={backUrl} variant="secondary" leftIcon={ArrowLeft}>
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
          <CardHeader title={isManager ? 'Manager details' : 'Member details'} />

          <div className="space-y-6">
            <TextInput
              id="foreman-name"
              label="Member Name*"
              placeholder="e.g. Dana Wu"
              autoComplete="off"
              value={data.name}
              onChange={(event) => update('name', toTitleCase(event.target.value))}
              {...(errors.name ? { error: errors.name } : {})}
            />

            <div className="grid gap-6 sm:grid-cols-2">
              {/*
                What the register is for: who supervises, and who runs the work.
              */}
              <SelectField
                id="foreman-role"
                label="Role*"
                options={roles.map((role) => ({ label: role.label, value: role.value }))}
                value={data.role}
                disabled={isManager}
                onChange={(event) => update('role', event.target.value)}
                {...(errors.role ? { error: errors.role } : {})}
              />

              {!isManager && (
                <>
                  {/*
                Blank is a real answer, and moving somebody between crews is
                just changing it — the work they are carrying stays with them.
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
                </>
              )}
            </div>

            {!isManager && (
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
            )}

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
                disabled={isManager && !manager.canEditEmail}
                {...(isManager && !manager.canEditEmail
                  ? { hint: 'Their sign-in email is changed from Security.' }
                  : {})}
                onChange={(event) => update('email', event.target.value)}
                {...(errors.email ? { error: errors.email } : {})}
              />
            </div>

            {!isManager && (
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
            )}
          </div>
        </Card>

        <div className="flex flex-wrap items-center justify-end gap-3">
          <ButtonLink href={backUrl} variant="white">
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

ForemanEdit.layout = appLayout
