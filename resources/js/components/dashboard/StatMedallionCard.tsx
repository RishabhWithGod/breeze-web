// import { Link } from '@inertiajs/react' // used again if the "View all" link is restored
import { motion } from 'framer-motion'
// import { ArrowRight } from 'lucide-react'
import { MOTION } from '@/constants'
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
 * Headline KPI tile for the dashboard hero: mono label with its icon, a large
 * display-font figure, and a deep link. Sits on the blueprint panel, so it
 * carries its own light-on-dark colours.
 */
export function StatMedallionCard({ stat, index = 0, className }: StatMedallionCardProps) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: MOTION.slow, delay: index * MOTION.stagger, ease: [0.22, 1, 0.36, 1] }}
      className={cn(
        'group relative flex min-w-0 flex-col overflow-hidden rounded-[14px] p-4',
        'border border-[rgb(126_170_255/0.14)] bg-white/[0.075] backdrop-blur-sm',
        'transition-colors duration-200 hover:bg-white/[0.11]',
        className,
      )}
    >
      <p className="relative flex items-center gap-1.5 text-xs font-semibold tracking-[0.04em] text-[#a7b7d9] uppercase">
        <FeedIcon name={stat.icon} size={13} aria-hidden />
        <span className="leading-tight">{stat.label}</span>
      </p>

      <p className="relative mt-1.5 font-display text-[1.625rem] leading-tight font-semibold tracking-tight text-white">
        <AnimatedCounter value={stat.value} />
      </p>

      {/* "View all …" link hidden for now.
      <Link
        href={stat.href}
        className="relative mt-1 inline-flex items-center gap-1 text-[13px] font-medium text-[#3ee6ff] transition-colors hover:text-white"
      >
        {stat.linkLabel}
        <ArrowRight size={13} aria-hidden className="transition-transform group-hover:translate-x-0.5" />
      </Link>
      */}
    </motion.div>
  )
}
