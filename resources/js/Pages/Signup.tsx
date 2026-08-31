import { useState } from 'react'
import { Head, Link, useForm } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Eye, EyeOff, Lock, Mail, User } from 'lucide-react'
import { Alert } from '@/components/common'
import { BrandWordmark } from '@/components/layout'
import { MOTION, ROUTES } from '@/constants'

interface SignupForm {
  name: string
  email: string
  password: string
  password_confirmation: string
}

/** Light-filled control, matching the sign-in screen's fields. Left padding
 *  clears the leading icon every field on this screen carries. */
const FIELD =
  'w-full rounded-lg border border-transparent bg-[#eef1fa] py-2.5 pr-4 pl-11 text-[0.9375rem] ' +
  'text-navy-950 placeholder:text-navy-950/45 shadow-[inset_0_1px_2px_rgb(3_10_25/0.08)] transition-shadow duration-200 ' +
  'focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand disabled:cursor-not-allowed disabled:opacity-60'

/**
 * Self-service account creation against the `users` table.
 *
 * Mirrors `Login`'s shell — same backdrop, wordmark and card treatment — so
 * the two screens read as one front door with two doors in it.
 */
export default function Signup() {
  const { data, setData, post, processing, errors, clearErrors } = useForm<SignupForm>({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
  })
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    post(ROUTES.signup, { onFinish: () => setData('password', '') })
  }

  const firstError = errors.name ?? errors.email ?? errors.password

  return (
    <div className="relative flex min-h-dvh flex-col items-center justify-center px-4 py-12 sm:px-6 sm:py-16">
      <Head title="Sign up" />

      <div
        aria-hidden
        className="pointer-events-none fixed inset-0 z-0 bg-[linear-gradient(135deg,rgb(26_86_190/0.5)_0%,rgb(16_56_140/0.32)_42%,rgb(8_20_54/0.12)_75%)]"
      />

      <div
        aria-hidden
        className="pointer-events-none absolute top-1/2 left-1/2 z-0 size-125 -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand/20 blur-[110px]"
      />

      <motion.div
        initial={{ opacity: 0, y: 18 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: MOTION.slow, ease: [0.22, 1, 0.36, 1] }}
        className="relative z-10 w-full max-w-md"
      >
        <div className="flex justify-center drop-shadow-[0_0_24px_rgb(51_227_255/0.35)]">
          <BrandWordmark />
        </div>

        <h1 className="mt-7 text-center text-2xl font-extrabold text-white sm:text-[1.75rem]">
          Create Account
        </h1>
        <p className="mt-2 text-center text-sm font-medium text-white/70">
          Sign up to get started
        </p>

        <form
          onSubmit={submit}
          noValidate
          className="mt-7 rounded-2xl border border-hairline-strong grad-spotlight p-6 shadow-glow backdrop-blur-xl sm:p-7"
        >
          <AnimatePresence initial={false}>
            {firstError && (
              <Alert
                key="signup-error"
                tone="danger"
                title="Sign-up failed"
                className="mb-5"
                onDismiss={clearErrors}
              >
                {firstError}
              </Alert>
            )}
          </AnimatePresence>

          <label htmlFor="name" className="block text-sm font-semibold text-white/90">
            Full Name
          </label>
          <div className="relative mt-2">
            <User
              aria-hidden
              size={18}
              className="pointer-events-none absolute inset-y-0 left-4 my-auto text-navy-950/45"
            />
            <input
              id="name"
              type="text"
              autoComplete="name"
              aria-invalid={Boolean(errors.name) || undefined}
              disabled={processing}
              value={data.name}
              onChange={(event) => setData('name', event.target.value)}
              className={FIELD}
            />
          </div>

          <label htmlFor="email" className="mt-4 block text-sm font-semibold text-white/90">
            Email
          </label>
          <div className="relative mt-2">
            <Mail
              aria-hidden
              size={18}
              className="pointer-events-none absolute inset-y-0 left-4 my-auto text-navy-950/45"
            />
            <input
              id="email"
              type="email"
              autoComplete="email"
              aria-invalid={Boolean(errors.email) || undefined}
              disabled={processing}
              value={data.email}
              onChange={(event) => setData('email', event.target.value)}
              className={FIELD}
            />
          </div>

          <label htmlFor="password" className="mt-4 block text-sm font-semibold text-white/90">
            Password
          </label>
          <div className="relative mt-2">
            <Lock
              aria-hidden
              size={18}
              className="pointer-events-none absolute inset-y-0 left-4 my-auto text-navy-950/45"
            />
            <input
              id="password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="new-password"
              aria-invalid={Boolean(errors.password) || undefined}
              disabled={processing}
              value={data.password}
              onChange={(event) => setData('password', event.target.value)}
              className={`${FIELD} pr-11`}
            />
            <button
              type="button"
              onClick={() => setShowPassword((visible) => !visible)}
              disabled={processing}
              aria-label={showPassword ? 'Hide password' : 'Show password'}
              aria-pressed={showPassword}
              className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-navy-950/60 transition-colors hover:text-navy-950 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {showPassword ? <EyeOff size={19} aria-hidden /> : <Eye size={19} aria-hidden />}
            </button>
          </div>

          <label
            htmlFor="password_confirmation"
            className="mt-4 block text-sm font-semibold text-white/90"
          >
            Confirm Password
          </label>
          <div className="relative mt-2">
            <Lock
              aria-hidden
              size={18}
              className="pointer-events-none absolute inset-y-0 left-4 my-auto text-navy-950/45"
            />
            <input
              id="password_confirmation"
              type={showConfirmPassword ? 'text' : 'password'}
              autoComplete="new-password"
              disabled={processing}
              value={data.password_confirmation}
              onChange={(event) => setData('password_confirmation', event.target.value)}
              className={`${FIELD} pr-11`}
            />
            <button
              type="button"
              onClick={() => setShowConfirmPassword((visible) => !visible)}
              disabled={processing}
              aria-label={showConfirmPassword ? 'Hide password' : 'Show password'}
              aria-pressed={showConfirmPassword}
              className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-navy-950/60 transition-colors hover:text-navy-950 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {showConfirmPassword ? (
                <EyeOff size={19} aria-hidden />
              ) : (
                <Eye size={19} aria-hidden />
              )}
            </button>
          </div>

          <button
            type="submit"
            disabled={processing}
            className="mt-6 w-full rounded-lg bg-linear-to-b from-brand-soft to-brand py-2.5 text-[0.9375rem] font-bold text-brand-ink shadow-[0_6px_20px_-6px_rgb(51_227_255/0.6)] transition-all duration-200 hover:shadow-[0_8px_26px_-6px_rgb(51_227_255/0.8)] hover:brightness-105 active:brightness-95 focus-visible:outline-2 focus-visible:outline-brand focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-70 disabled:shadow-none disabled:hover:brightness-100"
          >
            {processing ? 'Creating Account…' : 'Sign Up'}
          </button>
        </form>

        <p className="mt-6 text-center text-sm font-medium text-white/70">
          Already have an account?{' '}
          <Link href={ROUTES.login} className="font-semibold text-brand transition-colors hover:text-white">
            Sign In
          </Link>
        </p>
      </motion.div>
    </div>
  )
}
