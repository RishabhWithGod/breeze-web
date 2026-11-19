import { Panel } from '@/components/common/Panel'
import { cn } from '@/utils'

/** A titled card on the plan screens — the app's Panel: head over a hairline, then the body. */
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
    <Panel title={title} {...(actions ? { actions } : {})} pad={false} className={cn('h-auto overflow-hidden', className)}>
      {children}
    </Panel>
  )
}

/** One label and its value, on a line. */
export function PlanRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4 px-4 py-2.5 text-sm">
      <dt className="text-[var(--text-2)]">{label}</dt>
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
        <span className={cn('tabular-nums', over ? 'text-status-danger' : 'text-white/80')}>
          {used} / {limit}
          {unit ? ` ${unit}` : ''}
        </span>
      </div>
      <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-[var(--surface-3)]" aria-hidden>
        <div
          className={cn('h-full rounded-full', over ? 'bg-status-danger' : 'bg-brand')}
          style={{ width: `${pct}%` }}
        />
      </div>
    </li>
  )
}
