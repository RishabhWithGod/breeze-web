import type { LucideIcon } from 'lucide-react'
import { cn } from '@/utils'

const TONE = {
  cyan: { border: 'border-brand/70', icon: 'bg-brand/15 text-brand' },
  blue: { border: 'border-status-blue/70', icon: 'bg-status-blue/15 text-status-blue' },
  red: { border: 'border-status-danger/80', icon: 'bg-status-danger/20 text-red-300' },
  green: { border: 'border-status-success/70', icon: 'bg-status-success/15 text-status-success' },
} as const

export type MetricTone = keyof typeof TONE

export interface MetricCardProps {
  label: string
  value: string
  icon: LucideIcon
  tone: MetricTone
  /** A line under the number — what it is a share of, or who it is for. */
  note?: string | undefined
}

/** One key figure: what it is, top left; its icon, top right; the number, large. */
export function MetricCard({ label, value, icon: Icon, tone, note }: MetricCardProps) {
  return (
    <div className={cn('rounded-card border-2 bg-white/4 p-5 backdrop-blur-sm', TONE[tone].border)}>
      <div className="flex items-start justify-between gap-3">
        <p className="text-md font-medium text-white/90">{label}</p>
        <span className={cn('grid size-10 shrink-0 place-items-center rounded-full', TONE[tone].icon)}>
          <Icon size={18} aria-hidden />
        </span>
      </div>
      <p className="mt-4 text-3xl font-bold tabular-nums text-white">{value}</p>
      {note && <p className="mt-0.5 text-xs text-white/65">{note}</p>}
    </div>
  )
}
