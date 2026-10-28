import { cn } from '@/utils'

/** A titled card on the plan screens — the same look as the sections of Payment Setup. */
export function PlanPanel({
  title,
  actions,
  children,
  className,
}: {
  title: string
  actions?: React.ReactNode
  children: React.ReactNode
  className?: string
}) {
  return (
    <section className={cn('overflow-hidden rounded-card border border-hairline glass', className)}>
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-hairline bg-linear-to-b from-ocean-700/50 to-ocean-700/10 px-4 py-3">
        <h2 className="text-lg font-semibold text-white">{title}</h2>
        {actions}
      </header>
      {children}
    </section>
  )
}

/** One label and its value, on a line. */
export function PlanRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4 px-4 py-2.5 text-sm">
      <dt className="text-white/70">{label}</dt>
      <dd className="text-right text-white">{children}</dd>
    </div>
  )
}

/** A usage bar: how much of a limit is used, going red when it is over. */
export function UsageBar({
  label,
  used,
  limit,
  unit,
}: {
  label: string
  used: number
  limit: number
  unit: string | null
}) {
  const pct = limit > 0 ? Math.min(100, (used / limit) * 100) : 0
  const over = used > limit

  return (
    <li className="px-4 py-3">
      <div className="flex items-baseline justify-between gap-3 text-sm">
        <span className="text-white/90">{label}</span>
        <span className={cn('tabular-nums', over ? 'text-red-300' : 'text-white/80')}>
          {used} / {limit}
          {unit ? ` ${unit}` : ''}
        </span>
      </div>
      <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-white/12" aria-hidden>
        <div
          className={cn('h-full rounded-full', over ? 'bg-status-danger' : 'bg-brand')}
          style={{ width: `${pct}%` }}
        />
      </div>
    </li>
  )
}
