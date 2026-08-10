import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { ArrowRight, type LucideIcon } from 'lucide-react'
import { IconBubble } from '@/components/common'
import { MOTION } from '@/constants'
import type { Tone } from '@/types'
import { cn } from '@/utils'

export interface QuickActionCardProps {
  title: string
  description: string
  icon: LucideIcon
  href: string
  cta: string
  tone?: Tone
  index?: number
  className?: string
}

/**
 * Large tappable action card: icon, title, description and a hover lift.
 * Kept in the library for secondary dashboards and empty states.
 */
export function QuickActionCard({
  title,
  description,
  icon,
  href,
  cta,
  tone = 'brand',
  index = 0,
  className,
}: QuickActionCardProps) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 18 }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, amount: 0.2 }}
      whileHover={{ y: -6 }}
      transition={{
        duration: MOTION.slow,
        delay: index * MOTION.stagger,
        ease: [0.22, 1, 0.36, 1],
      }}
      className={cn('min-w-0', className)}
    >
      <Link
        href={href}
        className={cn(
          'group relative flex h-full items-start gap-5 overflow-hidden rounded-card border border-hairline',
          'glass p-6 shadow-panel transition-colors duration-300',
          'hover:border-brand/50 hover:bg-white/10',
        )}
      >
        {/* Sweep highlight on hover. */}
        <span
          aria-hidden
          className="pointer-events-none absolute inset-y-0 -left-1/3 w-1/3 -skew-x-12 bg-linear-to-r from-transparent via-white/8 to-transparent transition-transform duration-700 group-hover:translate-x-[400%]"
        />

        <IconBubble icon={icon} tone={tone} size="lg" className="relative" />

        <span className="relative min-w-0 flex-1">
          <span className="block text-lg font-semibold text-white">{title}</span>
          <span className="mt-1.5 block text-md text-white/90">{description}</span>
          <span className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand transition-colors group-hover:text-white">
            {cta}
            <ArrowRight
              size={15}
              aria-hidden
              className="transition-transform group-hover:translate-x-1"
            />
          </span>
        </span>
      </Link>
    </motion.div>
  )
}
