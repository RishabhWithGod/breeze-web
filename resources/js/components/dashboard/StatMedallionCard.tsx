import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { ChevronRight } from 'lucide-react'
import { MOTION } from '@/constants'
import { cardAccentAt } from '@/components/common'
import { FeedIcon } from '@/lib/icons'
import type { DashboardSummary } from '@/types'
import { cn } from '@/utils'
import { AnimatedCounter } from './AnimatedCounter'

export interface StatMedallionCardProps {
  stat: DashboardSummary
  index?: number
  className?: string
}

/**
 * Headline summary card: large circular icon medallion, an accent figure with
 * its label, and a corner deep-link — the reference dashboard's hero-stat tile.
 */
export function StatMedallionCard({
  stat,
  index = 0,
  className,
}: StatMedallionCardProps) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 22 }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, amount: 0.2 }}
      whileHover={{ y: -6 }}
      transition={{
        duration: MOTION.slow,
        delay: index * MOTION.stagger,
        ease: [0.22, 1, 0.36, 1],
      }}
      className={cn(
        'group relative flex min-w-0 flex-col overflow-hidden rounded-card',
        'glass px-6 py-8 shadow-panel',
        /*
         * The same lit border every other card in the app wears, walked across
         * the three so no two are the same colour. These are the first thing on
         * the dashboard and they were the last thing still drawn in a hairline.
         */
        cardAccentAt(index),
        'transition-colors duration-300 hover:bg-white/10',
        className,
      )}
    >
      {/* Corner glow on hover. */}
      <span
        aria-hidden
        className="pointer-events-none absolute -top-20 -right-12 size-48 rounded-full bg-brand/0 blur-3xl transition-colors duration-500 group-hover:bg-brand/20"
      />

      <span className="relative mx-auto grid size-24 place-items-center rounded-full bg-white/25 text-white ring-1 ring-white/15 transition-colors duration-300 group-hover:bg-white/32 sm:size-30">
        {/* Tiles are built server-side, so the icon arrives as a key. */}
        <FeedIcon name={stat.icon} className="size-10 sm:size-12" aria-hidden />
      </span>

      <p className="relative mt-7 text-center text-md font-medium text-white">
        <span className="text-lg font-semibold text-brand">
          <AnimatedCounter value={stat.value} className="tabular-nums" />
        </span>{' '}
        {stat.label}
      </p>

      <Link
        href={stat.href}
        className="relative mt-6 ml-auto inline-flex items-center gap-1 text-md font-medium text-white transition-colors hover:text-brand"
      >
        {stat.linkLabel}
        <ChevronRight
          size={16}
          aria-hidden
          className="transition-transform group-hover:translate-x-0.5"
        />
      </Link>
    </motion.div>
  )
}
