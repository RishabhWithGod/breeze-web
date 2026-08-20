import { useState } from 'react'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Alert } from '@/components/common'
import { BrandWordmark } from '@/components/layout'
import { MOTION, ROUTES } from '@/constants'

export interface TwoFactorChallengeProps {
  maskedEmail: string | null
}

const FIELD =
  'w-full rounded-md border border-transparent bg-[#eef1fa] px-4 py-2.5 text-[1.0625rem] ' +
  'text-navy-950 placeholder:text-navy-950/45 transition-shadow duration-200 ' +
  'focus:outline-none focus:ring-2 focus:ring-brand disabled:cursor-not-allowed disabled:opacity-60'

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
    <div className="relative flex min-h-dvh flex-col items-center px-4 py-12 sm:px-6 sm:py-16">
      <Head title="Verify it's you" />

      <div
        aria-hidden
        className="pointer-events-none fixed inset-0 z-0 bg-[linear-gradient(135deg,rgb(26_86_190/0.5)_0%,rgb(16_56_140/0.32)_42%,rgb(8_20_54/0.12)_75%)]"
      />

      <motion.div
        initial={{ opacity: 0, y: 18 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: MOTION.slow, ease: [0.22, 1, 0.36, 1] }}
        className="relative z-10 w-full max-w-3xl"
      >
        <div className="flex justify-center">
          <BrandWordmark />
        </div>

        <h1 className="mt-6 text-center text-3xl font-extrabold text-white sm:text-4xl">Verify It's You</h1>
        <p className="mt-3 text-center text-lg font-bold text-white sm:text-xl">
          {maskedEmail ? `Enter the code we sent to ${maskedEmail}` : 'Enter your verification code'}
        </p>

        <form onSubmit={submit} noValidate className="mt-8 rounded-xl border border-hairline grad-spotlight p-5 shadow-raised backdrop-blur-xl sm:p-6">
          <AnimatePresence initial={false}>
            {errors.code && (
              <Alert key="2fa-error" tone="danger" title="Verification failed" className="mb-5" onDismiss={clearErrors}>
                {errors.code}
              </Alert>
            )}
          </AnimatePresence>

          {useRecoveryCode ? (
            <>
              <label htmlFor="recovery_code" className="block text-lg font-medium text-white">
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
              <label htmlFor="code" className="block text-lg font-medium text-white">
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

          <div className="mt-5 flex flex-wrap items-center justify-between gap-3 text-lg text-white">
            <button type="button" onClick={resend} className="text-brand transition-colors hover:text-white">
              Resend code
            </button>
            <button
              type="button"
              onClick={() => setUseRecoveryCode((value) => !value)}
              className="text-brand transition-colors hover:text-white"
            >
              {useRecoveryCode ? 'Use verification code instead' : 'Use a recovery code instead'}
            </button>
          </div>

          <button
            type="submit"
            disabled={processing}
            className="mt-6 w-full rounded-md bg-brand py-3 text-lg font-bold text-brand-ink transition-colors duration-200 hover:bg-brand-soft focus-visible:outline-2 focus-visible:outline-brand focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-70"
          >
            {processing ? 'Verifying…' : 'Verify'}
          </button>
        </form>
      </motion.div>
    </div>
  )
}
