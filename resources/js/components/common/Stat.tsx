import type { ReactNode } from 'react'
import { cn } from '@/utils'

export interface StatProps {
  label: ReactNode
  value: ReactNode
  /** The small line under the figure. */
  delta?: ReactNode
  icon?: ReactNode
  className?: string
}

/** The Breeze app's stat tile: small caps label, a large display-font figure, a soft corner glow. */
export function Stat({ label, value, delta, icon, className }: StatProps) {
  return (
    <div className={cn('glass relative overflow-hidden rounded-card border border-hairline px-5 py-[18px]', className)}>
      <span
        aria-hidden
        className="pointer-events-none absolute -top-10 -right-10 size-[120px] rounded-full bg-[radial-gradient(circle,var(--glow-a),transparent_70%)]"
      />
      <div className="relative flex items-center gap-1.5 text-xs font-semibold tracking-[0.04em] text-[var(--text-2)] uppercase">
        {icon}
        {label}
      </div>
      <div className="relative mt-2 font-display text-[1.875rem] leading-normal font-[650] tracking-[-0.02em] text-white tabular-nums">
        {value}
      </div>
      {delta && <div className="relative mt-1 text-[12.5px] text-[var(--text-3)]">{delta}</div>}
    </div>
  )
}
