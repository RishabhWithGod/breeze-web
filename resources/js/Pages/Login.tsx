import { useState } from 'react'
import { Head, Link, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { Eye, EyeOff, Lock, Mail } from 'lucide-react'
import { Alert } from '@/components/common'
import { AuthLayout } from '@/components/layout'
import { ROUTES } from '@/constants'

interface LoginForm {
  email: string
  password: string
  remember: boolean
}

export interface LoginProps {
  /** Set when the last sign-in ticked "Remember me". */
  rememberedEmail: string | null
}

/** Light-filled control, as the sign-in screen renders its two fields. Left
 *  padding clears the leading icon every field on this screen carries. */
const FIELD =
  'w-full rounded-panel border border-hairline-strong bg-surface-input py-2.5 pr-4 pl-11 text-[0.9375rem] ' +
  'text-white placeholder:text-white/45 transition-colors duration-200 hover:border-brand/40 ' +
  'focus:border-brand focus:outline-none focus:ring-[3px] focus:ring-brand/25 disabled:cursor-not-allowed disabled:opacity-60'

/**
 * Sign-in against Laravel's session guard.
 *
 * Its own single-column shell rather than `AuthLayout`: this screen is the
 * product's front door and is laid out to the brand design — logo, heading, one
 * glass card, nothing beside it.
 */
export default function Login({ rememberedEmail }: LoginProps) {
  const { data, setData, post, processing, errors, clearErrors } = useForm<LoginForm>({
    email: rememberedEmail ?? '',
    password: '',
    remember: Boolean(rememberedEmail),
  })
  const [showPassword, setShowPassword] = useState(false)

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    // The server redirects to the intended page on success.
    post(ROUTES.login, { onFinish: () => setData('password', '') })
  }

  return (
    <AuthLayout
      title="Sign In"
      subtitle="Enter your credentials to access your account"
    >
      <Head title="Sign in" />
        <form onSubmit={submit} noValidate>
          <AnimatePresence initial={false}>
            {errors.email && (
              <Alert
                key="auth-error"
                tone="danger"
                title="Sign-in failed"
                className="mb-5"
                onDismiss={clearErrors}
              >
                {errors.email}
              </Alert>
            )}
          </AnimatePresence>

          <label htmlFor="email" className="block text-sm font-semibold text-white/90">
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

          <label
            htmlFor="password"
            className="mt-4 block text-sm font-semibold text-white/90"
          >
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
              autoComplete="current-password"
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
          {errors.password && (
            <p className="mt-2 text-sm text-status-danger">{errors.password}</p>
          )}

          <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
            {/*
              A white box rather than the app's cyan-on-glass checkbox, which is
              what this screen shows; the native input underneath keeps keyboard
              and screen-reader behaviour standard.
            */}
            <label
              htmlFor="remember"
              className="group inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium text-white/85 select-none has-[input:disabled]:cursor-not-allowed has-[input:disabled]:opacity-60"
            >
              <input
                id="remember"
                type="checkbox"
                className="sr-only"
                disabled={processing}
                checked={data.remember}
                onChange={(event) => setData('remember', event.target.checked)}
              />
              <span
                aria-hidden
                className="grid size-4.5 shrink-0 place-items-center rounded-xs border border-hairline-strong bg-surface-input transition-colors group-has-[input:checked]:bg-brand group-has-[input:focus-visible]:outline-2 group-has-[input:focus-visible]:outline-brand group-has-[input:focus-visible]:outline-offset-2"
              >
                <svg
                  viewBox="0 0 14 14"
                  className="size-3 scale-0 text-brand-ink transition-transform duration-150 group-has-[input:checked]:scale-100"
                >
                  <path
                    d="M2 7.5 5.2 11 12 3.5"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </span>
              Remember me
            </label>

            <Link
              href={ROUTES.forgotPassword}
              className="text-sm font-semibold text-brand transition-colors hover:text-brand-deep"
            >
              Forgot Password?
            </Link>
          </div>

          <button
            type="submit"
            disabled={processing}
            className="mt-6 w-full rounded-panel bg-brand [background-image:var(--brand-grad)] py-2.5 text-[0.9375rem] font-bold text-brand-ink shadow-[0_6px_18px_-6px_rgb(47_91_255/0.6)] transition-all duration-200 hover:shadow-[0_8px_26px_-6px_rgb(51_227_255/0.8)] hover:brightness-105 active:brightness-95 focus-visible:outline-2 focus-visible:outline-brand focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-70 disabled:shadow-none disabled:hover:brightness-100"
          >
            {processing ? 'Signing In…' : 'Sign In'}
          </button>
        </form>
    </AuthLayout>
  )
}
