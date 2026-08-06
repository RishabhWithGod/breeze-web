import type { ReactNode } from 'react'
import { motion } from 'framer-motion'
import { Check, ShieldCheck } from 'lucide-react'
import { APP_NAME, AUTH_HIGHLIGHTS, MOTION } from '@/constants'
import { Logo } from './Logo'

export interface AuthLayoutProps {
  title: string
  subtitle: string
  /** Rendered under the form card — e.g. "Back to login". */
  footer?: ReactNode
  children: ReactNode
}

/**
 * Shell for the public auth screens: marketing panel on the left, form card on
 * the right, collapsing to a single column below `lg`. Deliberately separate
 * from `AppLayout` — no navbar, sidebar or footer here.
 */
export function AuthLayout({ title, subtitle, footer, children }: AuthLayoutProps) {
  return (
    <div className="grid min-h-dvh place-items-center px-4 py-10 sm:px-6">
      <div className="grid w-full max-w-6xl items-center gap-10 lg:grid-cols-2 xl:gap-16">
        {/* Marketing panel */}
        <motion.section
          initial={{ opacity: 0, x: -24 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: MOTION.slow, ease: [0.22, 1, 0.36, 1] }}
          className="hidden lg:block"
        >
          <Logo />

          <h1 className="mt-10 text-4xl font-black text-white xl:text-5xl">
            Electrical takeoffs,
            <span className="block text-brand">done by AI.</span>
          </h1>

          <p className="mt-5 max-w-md text-base text-white/70">
            Upload a drawing set and {APP_NAME} counts every device, prices the
            material and estimates the labour — before your coffee goes cold.
          </p>

          <ul className="mt-9 space-y-4">
            {AUTH_HIGHLIGHTS.map((highlight, index) => (
              <motion.li
                key={highlight}
                initial={{ opacity: 0, x: -12 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{
                  duration: MOTION.base,
                  delay: 0.2 + index * MOTION.stagger,
                }}
                className="flex items-start gap-3 text-md text-white/80"
              >
                <span className="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-brand/20 text-brand">
                  <Check size={13} strokeWidth={3} aria-hidden />
                </span>
                {highlight}
              </motion.li>
            ))}
          </ul>

          <p className="mt-10 flex items-center gap-2 text-sm text-white/45">
            <ShieldCheck size={15} aria-hidden className="text-brand/80" />
            UI prototype — mock authentication, no data leaves your browser.
          </p>
        </motion.section>

        {/* Form card */}
        <motion.section
          initial={{ opacity: 0, y: 22 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: MOTION.slow, ease: [0.22, 1, 0.36, 1] }}
          className="w-full justify-self-center lg:max-w-md lg:justify-self-end"
        >
          <div className="mb-8 lg:hidden">
            <Logo />
          </div>

          <div className="rounded-card border border-hairline-strong grad-spotlight p-6 shadow-raised backdrop-blur-xl sm:p-8">
            <h2 className="text-2xl font-bold text-white">{title}</h2>
            <p className="mt-2 text-md text-white/65">{subtitle}</p>

            <div className="mt-7">{children}</div>
          </div>

          {footer && (
            <div className="mt-6 text-center text-md text-white/65">{footer}</div>
          )}
        </motion.section>
      </div>
    </div>
  )
}
