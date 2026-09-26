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
  password: string
  password_confirmation: string
}

export interface ForemanCreateProps {
  /** The crews someone can be put on. Empty until the first team is added. */
  teams: readonly { readonly id: number; readonly name: string }[]
  roles: readonly { readonly value: string; readonly label: string }[]
  /**
   * Opened from a job's task screen rather than the register: carried back to
   * `store()` so it can send the new member there instead, and back to `create()`
   * on the next visit if the task screen sends this member back to add another.
   */
  jobId: number | null
  /** That job's own crew, so it does not have to be picked again here. */
  defaultTeamId: number | null
  /** Where Back and a successful add go — that same task screen, or the register. */
  returnUrl: string | null
}

/**
 * Add Member.
 *
 * Name, role, email and password are required: the register exists to say who
 * runs work and who supervises it, and every member added here also gets a
 * real, already-approved mobile-app login — the email and password are what
 * they sign into it with. The rest is what you reach for once they are on the
 * register — a number to call, a licence to quote — so it stays optional.
 *
 * Initials are not asked for at all. "Dana Wu" gives "DW", and a field the app
 * can fill in itself is one more thing to type and one more thing to get wrong.
 */
export default function ForemanCreate({
  teams,
  roles,
  jobId,
  defaultTeamId,
  returnUrl,
}: ForemanCreateProps) {
  const backUrl = returnUrl ?? ROUTES.teams

  const { data, setData, post, processing, errors, hasErrors, clearErrors } =
    useForm<ForemanDraft>({
      name: '',
      // Most of the register is journeymen; a foreman is the exception you pick.
      role: 'journeyman',
      team_id: defaultTeamId === null ? '' : String(defaultTeamId),
      phone: '',
      email: '',
      licence_number: '',
      // Whoever is being added is joining today unless the form says
      // otherwise — a manager backdating a real earlier start is still
      // free to change it, but "unset" is never the right default for
      // someone being added right now.
      started_on: new Date().toISOString().slice(0, 10),
      notes: '',
      password: '',
      password_confirmation: '',
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
    // Carries the job along so `store()` can send this member back to that
    // job's task screen instead of the register.
    post(jobId === null ? ROUTES.foremen : `${ROUTES.foremen}?job=${jobId}`)
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
          <ButtonLink href={backUrl} variant="secondary" leftIcon={ArrowLeft}>
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
              onChange={(event) => update('name', toTitleCase(event.target.value))}
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
                label="Email*"
                type="email"
                placeholder="e.g. dana@example.com"
                autoComplete="off"
                value={data.email}
                onChange={(event) => update('email', event.target.value)}
                {...(errors.email ? { error: errors.email } : {})}
              />
            </div>

            {/*
              Every member added here also gets a mobile-app account — the
              email and password below are what they sign into it with,
              already approved, no manager review to wait on.
            */}
            <div className="border-t border-hairline pt-6">
              <p className="mb-1 text-md font-medium text-white">Mobile App Access</p>
              <p className="mb-4 text-sm text-white/70">
                This member signs into the mobile app with the email above and the password below.
              </p>

              <div className="grid gap-6 sm:grid-cols-2">
                <TextInput
                  id="foreman-password"
                  label="Password*"
                  type="password"
                  autoComplete="new-password"
                  value={data.password}
                  onChange={(event) => update('password', event.target.value)}
                  {...(errors.password ? { error: errors.password } : {})}
                />

                <TextInput
                  id="foreman-password-confirmation"
                  label="Confirm Password*"
                  type="password"
                  autoComplete="new-password"
                  value={data.password_confirmation}
                  onChange={(event) => update('password_confirmation', event.target.value)}
                />
              </div>
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
          <ButtonLink href={backUrl} variant="white">
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
