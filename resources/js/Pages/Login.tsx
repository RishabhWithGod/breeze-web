import { useState } from 'react'
import { Head, Link, useForm } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ArrowRight, Eye, EyeOff, KeyRound, Mail, Wand2 } from 'lucide-react'
import { Alert, Button, Checkbox, TextInput } from '@/components/common'
import { AuthLayout } from '@/components/layout'
import { DEMO_CREDENTIALS, ROUTES } from '@/constants'

interface LoginForm {
  email: string
  password: string
  remember: boolean
}

export interface LoginProps {
  /** Set when the last sign-in ticked "Remember me". */
  rememberedEmail: string | null
}

/** Sign-in against Laravel's session guard. */
export default function Login({ rememberedEmail }: LoginProps) {
  const [showPassword, setShowPassword] = useState(false)

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

  const fillDemoCredentials = () => {
    clearErrors()
    setData({
      ...data,
      email: DEMO_CREDENTIALS.email,
      password: DEMO_CREDENTIALS.password,
    })
  }

  return (
    <AuthLayout
      title="Welcome back"
      subtitle="Sign in to run AI takeoffs on your electrical drawings."
      footer={
        <>
          New to Breeze?{' '}
          <span className="text-brand">Ask your account owner for an invite.</span>
        </>
      }
    >
      <Head title="Sign in" />

      <motion.form
        onSubmit={submit}
        noValidate
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        className="space-y-5"
      >
        <AnimatePresence initial={false}>
          {errors.email && (
            <Alert
              key="auth-error"
              tone="danger"
              title="Sign-in failed"
              onDismiss={clearErrors}
            >
              {errors.email}
            </Alert>
          )}
        </AnimatePresence>

        <TextInput
          id="email"
          type="email"
          label="Email address"
          placeholder="you@company.com"
          autoComplete="email"
          leftIcon={Mail}
          disabled={processing}
          value={data.email}
          onChange={(event) => setData('email', event.target.value)}
        />

        <TextInput
          id="password"
          type={showPassword ? 'text' : 'password'}
          label="Password"
          placeholder="••••••••"
          autoComplete="current-password"
          leftIcon={KeyRound}
          disabled={processing}
          value={data.password}
          onChange={(event) => setData('password', event.target.value)}
          {...(errors.password ? { error: errors.password } : {})}
          rightSlot={
            <button
              type="button"
              onClick={() => setShowPassword((visible) => !visible)}
              aria-label={showPassword ? 'Hide password' : 'Show password'}
              aria-pressed={showPassword}
              className="grid size-8 place-items-center rounded-panel text-white/60 transition-colors hover:bg-white/10 hover:text-brand"
            >
              {showPassword ? (
                <EyeOff size={17} aria-hidden />
              ) : (
                <Eye size={17} aria-hidden />
              )}
            </button>
          }
        />

        <div className="flex flex-wrap items-center justify-between gap-3">
          <Checkbox
            id="remember"
            label="Remember me"
            disabled={processing}
            checked={data.remember}
            onChange={(event) => setData('remember', event.target.checked)}
          />
          <Link
            href={ROUTES.forgotPassword}
            className="text-md text-brand transition-colors hover:text-white"
          >
            Forgot password?
          </Link>
        </div>

        <Button
          type="submit"
          fullWidth
          size="lg"
          rightIcon={ArrowRight}
          isLoading={processing}
        >
          {processing ? 'Signing in…' : 'Sign in'}
        </Button>

        {/* The seeded demo account, so the app is reviewable out of the box. */}
        <div className="rounded-panel border border-hairline bg-navy-950/35 p-4">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-sm font-medium text-white">Demo account</p>
              <p className="mt-1 font-mono text-xs break-all text-white/60">
                {DEMO_CREDENTIALS.email} / {DEMO_CREDENTIALS.password}
              </p>
            </div>
            <Button
              type="button"
              variant="secondary"
              size="sm"
              leftIcon={Wand2}
              onClick={fillDemoCredentials}
              disabled={processing}
            >
              Fill
            </Button>
          </div>
        </div>
      </motion.form>
    </AuthLayout>
  )
}
