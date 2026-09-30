import type { ChangeOrderSource, ChangeOrderStatus } from '@/types'
import { cn } from '@/utils'

const PILL =
  'inline-flex items-center justify-center rounded-full border px-3 py-1 text-xs font-semibold whitespace-nowrap'

const STATUS: Record<ChangeOrderStatus, { label: string; className: string }> = {
  draft: { label: 'Draft', className: 'border-white/15 bg-white/10 text-white/90' },
  submitted: { label: 'Submitted', className: 'border-status-warning/50 bg-status-warning/15 text-status-warning' },
  approved: { label: 'Approved', className: 'border-status-success/50 bg-status-success/20 text-white' },
  rejected: { label: 'Rejected', className: 'border-status-danger/50 bg-status-danger/20 text-red-200' },
}

const SOURCE: Record<ChangeOrderSource, { label: string; className: string }> = {
  field: { label: 'Field', className: 'border-status-blue/50 bg-status-blue/25 text-white' },
  office: { label: 'Office', className: 'border-status-purple/50 bg-status-purple/30 text-white' },
}

export function ChangeOrderStatusPill({ status, className }: { status: ChangeOrderStatus; className?: string }) {
  return <span className={cn(PILL, 'min-w-24', STATUS[status].className, className)}>{STATUS[status].label}</span>
}

export function ChangeOrderSourcePill({ source, className }: { source: ChangeOrderSource; className?: string }) {
  return <span className={cn(PILL, SOURCE[source].className, className)}>{SOURCE[source].label}</span>
}
