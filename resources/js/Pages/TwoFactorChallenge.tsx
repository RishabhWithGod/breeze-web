import { useState } from 'react'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { Alert } from '@/components/common'
import { AuthLayout } from '@/components/layout'
import { ROUTES } from '@/constants'

export interface TwoFactorChallengeProps {
  maskedEmail: string | null
}

const FIELD =
  'w-full rounded-panel border border-hairline-strong bg-surface-input px-4 py-2.5 text-[1.0625rem] ' +
  'text-white placeholder:text-white/45 transition-colors duration-200 hover:border-brand/40 ' +
  'focus:border-brand focus:outline-none focus:ring-[3px] focus:ring-brand/25 disabled:cursor-not-allowed disabled:opacity-60'

/**
 * The login-time half of 2FA — reached only after a real credential check
 * already passed; the session is not established until the code here is
 * verified too, matching `Login`'s own single-column shell.
 */
export default function TwoFactorChallenge({ maskedEmail }: TwoFactorChallengeProps) {
  const [useRecoveryCode, setUseRecoveryCode] = useState(false)
  const { data, setData, post, processing, errors, clearErrors } = useForm({
    code: '',
    recovery_code: '',
  })

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    post(ROUTES.twoFactorChallenge)
  }

  const resend = () => {
    post(ROUTES.twoFactorChallengeResend)
  }

  return (
    <AuthLayout
      title="Verify It's You"
      subtitle={maskedEmail ? `Enter the code we sent to ${maskedEmail}` : 'Enter your verification code'}
    >
      <Head title="Verify it's you" />
        <form onSubmit={submit} noValidate>
          <AnimatePresence initial={false}>
            {errors.code && (
              <Alert key="2fa-error" tone="danger" title="Verification failed" className="mb-5" onDismiss={clearErrors}>
                {errors.code}
              </Alert>
            )}
          </AnimatePresence>

          {useRecoveryCode ? (
            <>
              <label htmlFor="recovery_code" className="block text-md font-medium text-white">
                Recovery Code
              </label>
              <input
                id="recovery_code"
                autoComplete="off"
                disabled={processing}
                value={data.recovery_code}
                onChange={(event) => setData('recovery_code', event.target.value)}
                className={`mt-2 ${FIELD}`}
              />
            </>
          ) : (
            <>
              <label htmlFor="code" className="block text-md font-medium text-white">
                Verification Code
              </label>
              <input
                id="code"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                disabled={processing}
                value={data.code}
                onChange={(event) => setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))}
                className={`mt-2 ${FIELD}`}
              />
            </>
          )}

          <div className="mt-5 flex flex-wrap items-center justify-between gap-3 text-md text-white">
            <button type="button" onClick={resend} className="text-brand transition-colors hover:text-brand-deep">
              Resend code
            </button>
            <button
              type="button"
              onClick={() => setUseRecoveryCode((value) => !value)}
              className="text-brand transition-colors hover:text-brand-deep"
            >
              {useRecoveryCode ? 'Use verification code instead' : 'Use a recovery code instead'}
            </button>
          </div>

          <button
            type="submit"
            disabled={processing}
            className="mt-6 w-full rounded-panel bg-brand [background-image:var(--brand-grad)] py-3 text-md font-bold text-brand-ink transition-colors duration-200 hover:brightness-110 focus-visible:outline-2 focus-visible:outline-brand focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-70"
          >
            {processing ? 'Verifying…' : 'Verify'}
          </button>
        </form>
    </AuthLayout>
  )
}
