import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ArrowLeft, Mail, MailCheck, Send } from 'lucide-react'
import { Alert, Button, TextInput } from '@/components/common'
import { AuthLayout } from '@/components/layout'
import { ROUTES } from '@/constants'

interface ForgotPasswordForm {
  email: string
}

/**
 * Password-reset request.
 *
 * The response is always a success regardless of whether the address is on
 * file — see PasswordResetLinkController.
 */
export default function ForgotPassword() {
  const sentTo = usePage().props['resetLinkSentTo'] as string | undefined

  const { data, setData, post, processing, errors, clearErrors } =
    useForm<ForgotPasswordForm>({ email: '' })

  const submit = (event?: React.FormEvent) => {
    event?.preventDefault()
    post(ROUTES.forgotPassword, { preserveScroll: true })
  }

  return (
    <AuthLayout
      title={sentTo ? 'Check your inbox' : 'Reset your password'}
      subtitle={
        sentTo
          ? 'If an account exists for that address, a reset link is on its way.'
          : 'Enter the email on your account and we will send a reset link.'
      }
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

      <AnimatePresence mode="wait">
        {sentTo ? (
          <motion.div
            key="sent"
            initial={{ opacity: 0, scale: 0.97 }}
            animate={{ opacity: 1, scale: 1 }}
            className="text-center"
          >
            <span className="relative mx-auto mb-5 grid size-16 place-items-center rounded-full bg-brand/15 text-brand">
              <span
                className="absolute inset-0 rounded-full bg-brand/25 animate-pulse-ring"
                aria-hidden
              />
              <MailCheck size={30} aria-hidden />
            </span>

            <p className="text-md text-white">
              We sent a reset link to{' '}
              <span className="font-semibold break-all text-white">{sentTo}</span>.
            </p>
            <p className="mt-2 text-sm text-white/75">
              The link expires in 30 minutes. Check your spam folder if it does not
              arrive.
            </p>

            <div className="mt-7 flex flex-col gap-3">
              <Button
                type="button"
                variant="secondary"
                fullWidth
                isLoading={processing}
                onClick={() => submit()}
              >
                Resend link
              </Button>
              <Button
                type="button"
                variant="ghost"
                fullWidth
                onClick={() => router.visit(ROUTES.forgotPassword)}
              >
                Use a different email
              </Button>
            </div>
          </motion.div>
        ) : (
          <motion.form
            key="form"
            onSubmit={submit}
            noValidate
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            className="space-y-5"
          >
            <AnimatePresence initial={false}>
              {errors.email && (
                <Alert key="reset-error" tone="danger" onDismiss={clearErrors}>
                  {errors.email}
                </Alert>
              )}
            </AnimatePresence>

            <TextInput
              id="reset-email"
              type="email"
              label="Email address"
              placeholder="you@company.com"
              autoComplete="email"
              leftIcon={Mail}
              disabled={processing}
              value={data.email}
              onChange={(event) => setData('email', event.target.value)}
            />

            <Button
              type="submit"
              fullWidth
              size="lg"
              leftIcon={Send}
              isLoading={processing}
            >
              {processing ? 'Sending link…' : 'Send reset link'}
            </Button>
          </motion.form>
        )}
      </AnimatePresence>
    </AuthLayout>
  )
}
