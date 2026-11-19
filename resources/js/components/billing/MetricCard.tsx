import type { LucideIcon } from 'lucide-react'
import { Stat } from '@/components/common/Stat'

// Kept only so existing callers' `tone` prop still type-checks.
const TONE = {
  cyan: { border: 'border-brand/70', icon: 'bg-brand/15 text-brand' },
  blue: { border: 'border-status-blue/70', icon: 'bg-status-blue/15 text-status-blue' },
  red: { border: 'border-status-danger/80', icon: 'bg-status-danger/20 text-status-danger' },
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

/** One key figure — the app's stat tile (the old coloured border and icon bubble are retired). */
export function MetricCard({ label, value, note }: MetricCardProps) {
  return <Stat label={label} value={value} {...(note ? { delta: note } : {})} className="h-full" />
}
