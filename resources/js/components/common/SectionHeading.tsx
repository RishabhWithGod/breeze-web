import type { ReactNode } from 'react'
import { motion } from 'framer-motion'
import { cn } from '@/utils'

export interface SectionHeadingProps {
  title: ReactNode
  subtitle?: ReactNode
  /** Sits above the title, inside the same column — a breadcrumb trail. */
  above?: ReactNode
  /** Right-aligned controls: search, filters, primary action. */
  actions?: ReactNode
  /** `h2` for page-level sections, `h3` inside cards. */
  as?: 'h1' | 'h2' | 'h3'
  className?: string
}

const SIZES = {
  // The app's h1: 31px, weight 650, tight tracking.
  h1: 'text-4xl font-[650] tracking-[-0.025em] leading-[1.1]',
  // The app's h2: 17px, Bricolage 620 — a section title, not a second page title.
  h2: 'text-xl leading-[1.25] font-[620] tracking-[-0.01em]',
  h3: 'text-xl leading-[1.25] font-[620] tracking-[-0.01em]',
} as const

export function SectionHeading({
  title,
  subtitle,
  above,
  actions,
  as = 'h2',
  className,
}: SectionHeadingProps) {
  const Heading = as

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: 'easeOut' }}
      className={cn(
        'flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between',
        className,
      )}
    >
      <div className="min-w-0">
        {above}
        <Heading className={cn('font-display text-white', SIZES[as])}>{title}</Heading>
        {subtitle && <p className="mt-1.5 text-base text-[var(--text-3)]">{subtitle}</p>}
      </div>
      {actions && (
        <div className="flex flex-wrap items-center gap-3 lg:shrink-0">{actions}</div>
      )}
    </motion.div>
  )
}
