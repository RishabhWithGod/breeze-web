import { Head, Link, useForm } from '@inertiajs/react'
import { ArrowLeft, KeyRound, Lock } from 'lucide-react'
import { Alert, Button, TextInput } from '@/components/common'
import { AuthLayout } from '@/components/layout'
import { ROUTES } from '@/constants'

interface ResetPasswordForm {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export interface ResetPasswordProps {
  token: string
  email: string
}

/** Lands from the emailed reset link — sets a new password against the token. */
export default function ResetPassword({ token, email }: ResetPasswordProps) {
  const { data, setData, post, processing, errors, clearErrors } =
    useForm<ResetPasswordForm>({
      token,
      email,
      password: '',
      password_confirmation: '',
    })

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    post('/reset-password', { preserveScroll: true })
  }

  return (
    <AuthLayout
      title="Set a new password"
      subtitle="Choose a new password for your account."
      footer={
        <Link
          href={ROUTES.login}
          className="inline-flex items-center gap-2 text-brand transition-colors hover:text-white"
        >
          <ArrowLeft size={15} aria-hidden />
          Back to login
        </Link>
      }
    >
      <Head title="Reset password" />

      <form onSubmit={submit} noValidate className="space-y-5">
        {(errors.email || errors.token) && (
          <Alert tone="danger" onDismiss={clearErrors}>
            {errors.email ?? errors.token}
          </Alert>
        )}

        <TextInput
          id="reset-email-locked"
          type="email"
          label="Email address"
          value={data.email}
          disabled
          leftIcon={KeyRound}
        />

        <TextInput
          id="reset-password"
          type="password"
          label="New password*"
          autoComplete="new-password"
          leftIcon={Lock}
          disabled={processing}
          value={data.password}
          onChange={(event) => setData('password', event.target.value)}
          error={errors.password}
        />

        <TextInput
          id="reset-password-confirmation"
          type="password"
          label="Confirm new password*"
          autoComplete="new-password"
          leftIcon={Lock}
          disabled={processing}
          value={data.password_confirmation}
          onChange={(event) => setData('password_confirmation', event.target.value)}
        />

        <Button type="submit" fullWidth size="lg" isLoading={processing}>
          {processing ? 'Resetting…' : 'Reset password'}
        </Button>
      </form>
    </AuthLayout>
  )
}
