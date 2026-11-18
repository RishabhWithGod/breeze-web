import type { ReactNode } from 'react'
import { motion } from 'framer-motion'
import { Check } from 'lucide-react'
import breezeLogoFull from '@/assets/breeze-logo-full.png'
import { AUTH_HIGHLIGHTS, MOTION } from '@/constants'
import { Logo } from './Logo'

export interface AuthLayoutProps {
  title: string
  subtitle: string
  /** Rendered under the form card — e.g. "Back to login". */
  footer?: ReactNode
  children: ReactNode
}

/** The blueprint panel: dark navy, animated wire runs, logo and the pitch. */
function AuthHero() {
  return (
    <div className="relative hidden p-3.5 lg:block">
      <div className="auth-blueprint relative flex h-full min-h-[40rem] flex-col overflow-hidden rounded-[26px] p-12">
        <svg
          className="auth-wires pointer-events-none absolute inset-0 size-full"
          viewBox="0 0 600 800"
          preserveAspectRatio="xMidYMid slice"
          aria-hidden
        >
          <path d="M-20 520 H180 V380 H420 V260 H640" />
          <path className="b" d="M-20 640 H260 V500 H520 V420 H640" />
          <path d="M120 -20 V200 H300 V320" />
          <path className="b" d="M480 -20 V140 H360 V260" />
          <circle cx="180" cy="380" r="3.5" />
          <circle cx="420" cy="260" r="3.5" />
          <circle cx="260" cy="500" r="3" />
          <circle cx="300" cy="320" r="3.5" />
          <circle cx="520" cy="420" r="3" />
        </svg>

        <img
          src={breezeLogoFull}
          alt="Breeze.Ai — Smarter Software. Stronger Projects."
          className="relative h-14 w-auto self-start"
        />

        <p className="relative mt-auto font-mono text-2xs tracking-[0.14em] text-accent-bright uppercase">
          Electrical estimating, powered by AI
        </p>
        <h1 className="relative mt-4 max-w-[13ch] text-[clamp(2.2rem,3.6vw,3.25rem)] leading-[1.04] font-semibold text-white">
          From the first drawing <em className="auth-grad-text">to the final invoice.</em>
        </h1>
        <p className="relative mt-3 max-w-md text-md text-[#a9b8d6]">
          Upload a drawing set and Breeze counts every device, prices the material and estimates the
          labour — built for electrical contractors.
        </p>

        <ul className="relative mt-7 mb-auto grid gap-3">
          {AUTH_HIGHLIGHTS.map((highlight, index) => (
            <motion.li
              key={highlight}
              initial={{ opacity: 0, x: -12 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ duration: MOTION.base, delay: 0.2 + index * MOTION.stagger }}
              className="flex items-center gap-2.5 text-md text-[#d6e0f2]"
            >
              <span className="grid size-5 shrink-0 place-items-center rounded-full bg-accent-bright/15 text-accent-bright ring-1 ring-accent-bright/30">
                <Check size={12} strokeWidth={3} aria-hidden />
              </span>
              {highlight}
            </motion.li>
          ))}
        </ul>
      </div>
    </div>
  )
}

/**
 * Shell for the public auth screens, as in the Breeze app: a dark blueprint panel
 * on the left, the form in a card on the right, collapsing to the card alone
 * below `lg`. Deliberately separate from `AppLayout` — no navbar or sidebar.
 */
export function AuthLayout({ title, subtitle, footer, children }: AuthLayoutProps) {
  return (
    <div className="grid min-h-page lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
      <AuthHero />

      <motion.section
        initial={{ opacity: 0, y: 18 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: MOTION.slow, ease: [0.22, 1, 0.36, 1] }}
        className="flex items-center justify-center px-3.5 py-10 sm:px-6"
      >
        <div className="w-full max-w-[26.25rem]">
          <div className="mb-6 flex justify-center lg:hidden">
            <Logo />
          </div>

          <div className="rounded-[22px] border border-hairline bg-surface-glass p-6 shadow-card sm:p-9">
            <h2 className="text-[1.875rem] font-semibold tracking-tight text-white">{title}</h2>
            <p className="mt-1.5 mb-6 text-md text-white/65">{subtitle}</p>

            {children}
          </div>

          {footer && <div className="mt-6 text-center text-md text-white/70">{footer}</div>}
        </div>
      </motion.section>
    </div>
  )
}
