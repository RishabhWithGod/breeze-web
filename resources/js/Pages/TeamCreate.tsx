import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Users } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'

interface TeamDraft {
  name: string
}

/**
 * Add Team — a crew, before anyone is put on it.
 *
 * One field, because a team is its name. Who is on it is decided one member at
 * a time on the member's own form, and what the crew covers shows in the work
 * they are carrying — neither is worth a second box here that somebody then has
 * to keep true.
 */
export default function TeamCreate() {
  const { data, setData, post, processing, errors, hasErrors, clearErrors } =
    useForm<TeamDraft>({ name: '' })

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    post(ROUTES.teams)
  }

  return (
    <PageTransition>
      <Head title="Add Team" />

      <PageHeader
        title="Add Team"
        subtitle="A crew that work can be handed to."
        breadcrumbs={[{ label: 'Teams', href: ROUTES.teams }, { label: 'Add Team' }]}
        actions={
          <ButtonLink href={ROUTES.teams} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the form" className="mb-6">
          Some fields need attention before this team can be saved.
        </Alert>
      )}

      <form onSubmit={submit} noValidate>
        <Card padding="lg">
          <CardHeader title="Team details" subtitle="What this crew is called" />

          <TextInput
            id="team-name"
            label="Team Name*"
            placeholder="e.g. North Crew"
            autoComplete="off"
            value={data.name}
            onChange={(event) => {
              setData('name', event.target.value)
              if (errors.name) clearErrors('name')
            }}
            {...(errors.name ? { error: errors.name } : {})}
          />
        </Card>

        <div className="mt-6 flex flex-wrap items-center justify-end gap-3">
          <ButtonLink href={ROUTES.teams} variant="white">
            Cancel
          </ButtonLink>
          <Button type="submit" leftIcon={Users} isLoading={processing}>
            Add Team
          </Button>
        </div>
      </form>
    </PageTransition>
  )
}

TeamCreate.layout = appLayout
