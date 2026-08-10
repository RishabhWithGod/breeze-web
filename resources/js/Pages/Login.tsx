import { Head, Link, useForm } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Alert } from '@/components/common'
import { BrandWordmark } from '@/components/layout'
import { MOTION, ROUTES } from '@/constants'

interface LoginForm {
  email: string
  password: string
  remember: boolean
}

export interface LoginProps {
  /** Set when the last sign-in ticked "Remember me". */
  rememberedEmail: string | null
}

/** Light-filled control, as the sign-in screen renders its two fields. */
const FIELD =
  'w-full rounded-md border border-transparent bg-[#eef1fa] px-4 py-2.5 text-[1.0625rem] ' +
  'text-navy-950 placeholder:text-navy-950/45 transition-shadow duration-200 ' +
  'focus:outline-none focus:ring-2 focus:ring-brand disabled:cursor-not-allowed disabled:opacity-60'

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

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    // The server redirects to the intended page on success.
    post(ROUTES.login, { onFinish: () => setData('password', '') })
  }

  return (
    <div className="relative flex min-h-dvh flex-col items-center px-4 py-12 sm:px-6 sm:py-16">
      <Head title="Sign in" />

      {/*
        The app's backdrop is darkened for dense screens; the sign-in screen wants
        the artwork bright and its mesh visible, so this lifts it back — on this
        page only, leaving the global backdrop alone.
      */}
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

        <h1 className="mt-6 text-center text-3xl font-extrabold text-white sm:text-4xl">
          Sign In
        </h1>
        <p className="mt-3 text-center text-lg font-bold text-white sm:text-xl">
          Enter your credentials to access your account
        </p>

        <form
          onSubmit={submit}
          noValidate
          className="mt-8 rounded-xl border border-hairline grad-spotlight p-5 shadow-raised backdrop-blur-xl sm:p-6"
        >
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

          <label htmlFor="email" className="block text-lg font-medium text-white">
            Email
          </label>
          <input
            id="email"
            type="email"
            autoComplete="email"
            aria-invalid={Boolean(errors.email) || undefined}
            disabled={processing}
            value={data.email}
            onChange={(event) => setData('email', event.target.value)}
            className={`mt-2 ${FIELD}`}
          />

          <label
            htmlFor="password"
            className="mt-5 block text-lg font-medium text-white"
          >
            Password
          </label>
          <input
            id="password"
            type="password"
            autoComplete="current-password"
            aria-invalid={Boolean(errors.password) || undefined}
            disabled={processing}
            value={data.password}
            onChange={(event) => setData('password', event.target.value)}
            className={`mt-2 ${FIELD}`}
          />
          {errors.password && (
            <p className="mt-2 text-sm text-red-300">{errors.password}</p>
          )}

          <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
            {/*
              A white box rather than the app's cyan-on-glass checkbox, which is
              what this screen shows; the native input underneath keeps keyboard
              and screen-reader behaviour standard.
            */}
            <label
              htmlFor="remember"
              className="group inline-flex cursor-pointer items-center gap-3 text-lg text-white select-none has-[input:disabled]:cursor-not-allowed has-[input:disabled]:opacity-60"
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
                className="grid size-4.5 shrink-0 place-items-center rounded-xs bg-white transition-colors group-has-[input:checked]:bg-brand group-has-[input:focus-visible]:outline-2 group-has-[input:focus-visible]:outline-brand group-has-[input:focus-visible]:outline-offset-2"
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
              className="text-lg text-brand transition-colors hover:text-white"
            >
              Forgot Password?
            </Link>
          </div>

          <button
            type="submit"
            disabled={processing}
            className="mt-6 w-full rounded-md bg-brand py-3 text-lg font-bold text-brand-ink transition-colors duration-200 hover:bg-brand-soft focus-visible:outline-2 focus-visible:outline-brand focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-70"
          >
            {processing ? 'Signing In…' : 'Sign In'}
          </button>
        </form>

        <p className="mt-6 text-center text-lg font-bold text-white">
          Don&apos;t have an account?{' '}
          {/* No self-service registration exists, so this reads as brand copy. */}
          <span className="text-brand">Sign Up</span>
        </p>
      </motion.div>
    </div>
  )
}
