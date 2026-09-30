import {
  Banknote,
  Bell,
  Bot,
  Briefcase,
  Calendar,
  CheckSquare,
  Clock,
  FileText,
  Folder,
  Gift,
  LineChart,
  ShieldCheck,
  UsersRound,
  type LucideIcon,
} from 'lucide-react'
import type { NotificationTab } from '@/types'

export const NOTIFICATION_TABS: readonly { label: string; value: NotificationTab }[] = [
  { label: 'All', value: 'all' },
  { label: 'Unread', value: 'unread' },
  { label: 'Approvals', value: 'approvals' },
]

/** Icon shown per category — mirrors `AppNotification::CATEGORY_LABELS` on the backend. */
export const NOTIFICATION_CATEGORY_ICON: Record<string, LucideIcon> = {
  jobs: Briefcase,
  tasks: CheckSquare,
  estimates: FileText,
  'ai-takeoff': Bot,
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
  'ai-takeoff': { stripe: 'bg-status-success', tile: 'bg-emerald-700/70 text-white' },
  estimates: { stripe: 'bg-status-warning', tile: 'bg-amber-700/70 text-white' },
  scheduling: { stripe: 'bg-status-danger', tile: 'bg-red-800/70 text-white' },
  tasks: { stripe: 'bg-status-purple', tile: 'bg-violet-700/70 text-white' },
  jobs: { stripe: 'bg-status-blue', tile: 'bg-blue-700/70 text-white' },
  'time-tracking': { stripe: 'bg-brand', tile: 'bg-cyan-700/70 text-white' },
  documents: { stripe: 'bg-status-info', tile: 'bg-teal-700/70 text-white' },
  billing: { stripe: 'bg-status-success', tile: 'bg-green-700/70 text-white' },
  'job-costing': { stripe: 'bg-status-warning', tile: 'bg-orange-700/70 text-white' },
  security: { stripe: 'bg-brand', tile: 'bg-cyan-700/70 text-white' },
  teams: { stripe: 'bg-status-purple', tile: 'bg-violet-700/70 text-white' },
  'breeze-bucks': { stripe: 'bg-status-warning', tile: 'bg-amber-700/70 text-white' },
  general: { stripe: 'bg-white/50', tile: 'bg-slate-600/70 text-white' },
}
