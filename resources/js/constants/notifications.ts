import {
  Banknote,
  Bell,
  Calendar,
  CheckSquare,
  Clock,
  Folder,
  Gift,
  LineChart,
  ShieldCheck,
  UsersRound,
  type LucideIcon,
  ScanLine,
  Calculator,
  HardHat,
} from 'lucide-react'
import type { NotificationTab } from '@/types'

export const NOTIFICATION_TABS: readonly { label: string; value: NotificationTab }[] = [
  { label: 'All', value: 'all' },
  { label: 'Unread', value: 'unread' },
  { label: 'Approvals', value: 'approvals' },
]

/** Icon shown per category — mirrors `AppNotification::CATEGORY_LABELS` on the backend. */
export const NOTIFICATION_CATEGORY_ICON: Record<string, LucideIcon> = {
  jobs: HardHat,
  tasks: CheckSquare,
  estimates: Calculator,
  'ai-takeoff': ScanLine,
  'time-tracking': Clock,
  documents: Folder,
  billing: Banknote,
  'job-costing': LineChart,
  scheduling: Calendar,
  security: ShieldCheck,
  'breeze-bucks': Gift,
  teams: UsersRound,
  general: Bell,
}

/** A colour per category — its stripe, and the tile its icon sits on. */
export const NOTIFICATION_CATEGORY_TONE: Record<string, { stripe: string; tile: string }> = {
  'ai-takeoff': { stripe: 'bg-status-success', tile: 'bg-[var(--ok-bg)] text-[var(--ok-text)]' },
  estimates: { stripe: 'bg-status-warning', tile: 'bg-[var(--warn-bg)] text-[var(--warn-text)]' },
  scheduling: { stripe: 'bg-status-danger', tile: 'bg-[var(--danger-bg)] text-[var(--danger-text)]' },
  tasks: { stripe: 'bg-status-purple', tile: 'bg-[var(--violet-bg)] text-[var(--violet-text)]' },
  jobs: { stripe: 'bg-status-blue', tile: 'bg-[var(--brand-bg)] text-brand' },
  'time-tracking': { stripe: 'bg-brand', tile: 'bg-[var(--info-bg)] text-[var(--info-text)]' },
  documents: { stripe: 'bg-status-info', tile: 'bg-[var(--info-bg)] text-[var(--info-text)]' },
  billing: { stripe: 'bg-status-success', tile: 'bg-[var(--ok-bg)] text-[var(--ok-text)]' },
  'job-costing': { stripe: 'bg-status-warning', tile: 'bg-[var(--warn-bg)] text-[var(--warn-text)]' },
  security: { stripe: 'bg-brand', tile: 'bg-[var(--info-bg)] text-[var(--info-text)]' },
  teams: { stripe: 'bg-status-purple', tile: 'bg-[var(--violet-bg)] text-[var(--violet-text)]' },
  'breeze-bucks': { stripe: 'bg-status-warning', tile: 'bg-[var(--warn-bg)] text-[var(--warn-text)]' },
  general: { stripe: 'bg-white/50', tile: 'bg-[var(--gray-bg)] text-[var(--gray-text)]' },
}
