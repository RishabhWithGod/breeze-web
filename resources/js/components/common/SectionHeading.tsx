import type { ReactNode } from 'react'
import { motion } from 'framer-motion'
import { cn } from '@/utils'

export interface SectionHeadingProps {
  title: ReactNode
  subtitle?: ReactNode
  /** Right-aligned controls: search, filters, primary action. */
  actions?: ReactNode
  /** `h2` for page-level sections, `h3` inside cards. */
  as?: 'h1' | 'h2' | 'h3'
  className?: string
}

const SIZES = {
  h1: 'text-4xl sm:text-5xl font-black',
  h2: 'text-2xl sm:text-3xl font-bold',
  h3: 'text-xl font-semibold',
} as const

export function SectionHeading({
  title,
  subtitle,
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
        'flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between',
        className,
      )}
    >
      <div className="min-w-0">
        <Heading className={cn('text-white', SIZES[as])}>{title}</Heading>
        {subtitle && <p className="mt-2 text-base text-white/90">{subtitle}</p>}
      </div>
      {actions && (
        <div className="flex flex-wrap items-center gap-3 lg:shrink-0">{actions}</div>
      )}
    </motion.div>
  )
}
