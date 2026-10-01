import { Head, Link, useForm } from '@inertiajs/react'
import { CircleAlert, Lock, UserCheck } from 'lucide-react'
import { Alert, Button, TextInput } from '@/components/common'
import { AuthLayout } from '@/components/layout'
import { ROUTES } from '@/constants'

interface InvitationDetails {
  readonly company: string | null
  readonly inviter: string | null
  readonly name: string
  readonly email: string
  readonly role: string
  readonly team: string | null
}

export interface AcceptInvitationProps {
  state: 'ready' | 'invalid' | 'expired' | 'cancelled' | 'accepted'
  invitation: InvitationDetails | null
  token?: string
}

const CLOSED: Record<Exclude<AcceptInvitationProps['state'], 'ready'>, { title: string; body: string }> = {
  invalid: {
    title: 'This link does not work',
    body: 'The invitation link is not one we recognise. Ask whoever invited you to send it again.',
  },
  expired: {
    title: 'This invitation has expired',
    body: 'Invitations are open for a week. Ask whoever invited you to send a new one.',
  },
  cancelled: {
    title: 'This invitation was withdrawn',
    body: 'It is no longer open. If you think that is a mistake, ask whoever invited you.',
  },
  accepted: {
    title: 'You have already joined',
    body: 'This invitation was accepted and your account exists. Sign in to get to work.',
  },
}

/** Where an emailed invitation lands: see what you were invited to, choose a password, and you are in. */
export default function AcceptInvitation({ state, invitation, token }: AcceptInvitationProps) {
  const { data, setData, post, processing, errors } = useForm({ password: '', password_confirmation: '' })
  // Errors the form has no field for: the invitation itself, or the email being taken.
  const general =
    (errors as Record<string, string | undefined>)['invitation'] ??
    (errors as Record<string, string | undefined>)['email']

  if (state !== 'ready' || invitation === null || token === undefined) {
    const closed = CLOSED[state === 'ready' ? 'invalid' : state]

    return (
      <AuthLayout title={closed.title} subtitle={closed.body}>
        <Head title="Invitation" />
        <div className="space-y-5">
          <p className="flex items-center gap-3 text-md text-white/85">
            <CircleAlert size={22} aria-hidden className="shrink-0 text-status-warning" />
            {invitation?.company ? `${invitation.company} on Breeze.Ai` : 'Breeze.Ai'}
          </p>
          <Link href={ROUTES.login} className="inline-block font-semibold text-brand hover:underline">
            Go to sign in
          </Link>
        </div>
      </AuthLayout>
    )
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    post(`/invitations/${token}`)
  }

  return (
    <AuthLayout
      title={`Join ${invitation.company ?? 'the team'}`}
      subtitle={`${invitation.inviter ?? 'A manager'} invited you to Breeze.Ai. Choose a password to accept.`}
    >
      <Head title="Accept invitation" />

      <form onSubmit={submit} noValidate className="space-y-5">
        {general && <Alert tone="danger">{general}</Alert>}

        <dl className="divide-y divide-hairline rounded-panel border border-hairline bg-white/4 text-sm">
          {(
            [
              ['Name', invitation.name],
              ['Email', invitation.email],
              ['Role', invitation.role],
              ['Team', invitation.team ?? 'Not on a team yet'],
            ] as const
          ).map(([label, value]) => (
            <div key={label} className="flex items-center justify-between gap-4 px-4 py-2.5">
              <dt className="text-white/70">{label}</dt>
              <dd className="font-semibold text-white">{value}</dd>
            </div>
          ))}
        </dl>

        <TextInput
          id="invite-password"
          label="Password"
          type="password"
          autoComplete="new-password"
          leftIcon={Lock}
          value={data.password}
          onChange={(event) => setData('password', event.target.value)}
          {...(errors.password ? { error: errors.password } : {})}
        />
        <TextInput
          id="invite-password-confirmation"
          label="Confirm password"
          type="password"
          autoComplete="new-password"
          leftIcon={Lock}
          value={data.password_confirmation}
          onChange={(event) => setData('password_confirmation', event.target.value)}
        />

        <Button type="submit" fullWidth size="lg" leftIcon={UserCheck} isLoading={processing}>
          Accept and join
        </Button>
      </form>
    </AuthLayout>
  )
}
