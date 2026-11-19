import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import type { LucideIcon } from 'lucide-react'
import { MOTION } from '@/constants'
import { cn } from '@/utils'

export interface KpiTileProps {
  label: string
  value: string
  /** The small line under the figure. */
  detail?: string
  /** Paints the detail line in the alarm colour — e.g. money that is overdue. */
  alert?: boolean
  icon: LucideIcon
  href?: string
  index?: number
}

/**
 * A KPI tile for the hero where the figure is already formatted text (money,
 * durations). Same look as `StatMedallionCard`, which counts a plain number up.
 */
export function KpiTile({ label, value, detail, alert = false, icon: Icon, href, index = 0 }: KpiTileProps) {
  const body = (
    <>
      <p className="flex items-center gap-1.5 text-xs font-semibold tracking-[0.04em] text-[#a7b7d9] uppercase">
        <Icon size={13} aria-hidden />
        <span className="leading-tight">{label}</span>
      </p>
      <p className="mt-1.5 font-display text-[1.625rem] leading-tight font-semibold tracking-tight text-white tabular-nums">
        {value}
      </p>
      {detail && <p className={cn('mt-0.5 text-[13px]', alert ? 'text-[#ff8a8a]' : 'text-[#a7b7d9]')}>{detail}</p>}
    </>
  )
  const cls =
    'relative block h-full min-w-0 overflow-hidden rounded-[14px] border border-[rgb(126_170_255/0.14)] bg-white/[0.075] p-4 backdrop-blur-sm transition-colors duration-200 hover:bg-white/[0.11]'

  return (
    <motion.div
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: MOTION.slow, delay: index * MOTION.stagger, ease: [0.22, 1, 0.36, 1] }}
      className="h-full"
    >
      {href ? (
        <Link href={href} className={cls}>
          {body}
        </Link>
      ) : (
        <div className={cls}>{body}</div>
      )}
    </motion.div>
  )
}
