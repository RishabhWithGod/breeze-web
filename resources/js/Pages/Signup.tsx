import { useState } from 'react'
import { Head, Link, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { Eye, EyeOff, Lock, Mail, User } from 'lucide-react'
import { Alert } from '@/components/common'
import { AuthLayout } from '@/components/layout'
import { ROUTES } from '@/constants'
import { toTitleCase } from '@/utils'

interface SignupForm {
  name: string
  email: string
  password: string
  password_confirmation: string
}

/** Light-filled control, matching the sign-in screen's fields. Left padding
 *  clears the leading icon every field on this screen carries. */
const FIELD =
  'w-full rounded-panel border border-hairline-strong bg-surface-input py-2.5 pr-4 pl-11 text-[0.9375rem] ' +
  'text-white placeholder:text-white/45 transition-colors duration-200 hover:border-brand/40 ' +
  'focus:border-brand focus:outline-none focus:ring-[3px] focus:ring-brand/25 disabled:cursor-not-allowed disabled:opacity-60'

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
    <AuthLayout
      title="Create Account"
      subtitle="Sign up to get started"
      footer={
        <>
          Already have an account?{' '}
          <Link href={ROUTES.login} className="font-semibold text-brand transition-colors hover:text-brand-deep">
            Sign In
          </Link>
        </>
      }
    >
      <Head title="Sign up" />
        <form onSubmit={submit} noValidate>
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
              className="pointer-events-none absolute inset-y-0 left-4 my-auto text-white/45"
            />
            <input
              id="name"
              type="text"
              autoComplete="name"
              aria-invalid={Boolean(errors.name) || undefined}
              disabled={processing}
              value={data.name}
              onChange={(event) => setData('name', toTitleCase(event.target.value))}
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
              className="pointer-events-none absolute inset-y-0 left-4 my-auto text-white/45"
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
              className="pointer-events-none absolute inset-y-0 left-4 my-auto text-white/45"
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
              className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-white/60 transition-colors hover:text-white disabled:cursor-not-allowed disabled:opacity-60"
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
              className="pointer-events-none absolute inset-y-0 left-4 my-auto text-white/45"
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
              className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-white/60 transition-colors hover:text-white disabled:cursor-not-allowed disabled:opacity-60"
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
            className="mt-6 w-full rounded-panel bg-brand [background-image:var(--brand-grad)] py-2.5 text-[0.9375rem] font-bold text-brand-ink shadow-[0_6px_18px_-6px_rgb(47_91_255/0.6)] transition-all duration-200 hover:shadow-[0_8px_26px_-6px_rgb(51_227_255/0.8)] hover:brightness-105 active:brightness-95 focus-visible:outline-2 focus-visible:outline-brand focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-70 disabled:shadow-none disabled:hover:brightness-100"
          >
            {processing ? 'Creating Account…' : 'Sign Up'}
          </button>
        </form>
    </AuthLayout>
  )
}
