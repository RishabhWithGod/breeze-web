import { CheckCircle2, Clock, MapPin, TriangleAlert, type LucideIcon } from 'lucide-react'
import type { TimeLogRow } from '@/types'
import { cn } from '@/utils'

export type SessionStatusKey = TimeLogRow['status']

const STYLE: Record<SessionStatusKey, { label: string; icon: LucideIcon; classes: string }> = {
  approved: {
    label: 'Approved',
    icon: CheckCircle2,
    classes: 'border-status-success/60 bg-status-success/12 text-status-success',
  },
  completed: {
    label: 'Completed',
    icon: CheckCircle2,
    classes: 'border-status-success/60 bg-status-success/12 text-status-success',
  },
  draft: {
    label: 'Draft',
    icon: Clock,
    classes: 'border-hairline-strong bg-white/10 text-white/85',
  },
  pending: {
    label: 'Pending approval',
    icon: Clock,
    classes: 'border-status-blue/60 bg-status-blue/12 text-status-blue',
  },
  'on-site': {
    label: 'On site',
    icon: MapPin,
    classes: 'border-status-info/60 bg-status-info/12 text-status-info',
  },
  rejected: {
    label: 'Needs review',
    icon: TriangleAlert,
    classes: 'border-status-danger/60 bg-status-danger/20 text-red-300',
  },
  'missing-checkout': {
    label: 'Missing Checkout',
    icon: TriangleAlert,
    classes: 'border-status-warning/70 bg-status-warning/12 text-status-warning',
  },
}

/** One session's state as a pill with its icon — the same everywhere time is listed. */
export function SessionStatus({ status, className }: { status: SessionStatusKey; className?: string }) {
  const { label, icon: Icon, classes } = STYLE[status]

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold whitespace-nowrap',
        classes,
        className,
      )}
    >
      <Icon size={14} aria-hidden />
      {label}
    </span>
  )
}
