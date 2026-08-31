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
  LineChart,
  type LucideIcon,
} from 'lucide-react'
import type { NotificationTab } from '@/types'

export const NOTIFICATION_TABS: readonly { label: string; value: NotificationTab }[] = [
  { label: 'All Notifications', value: 'all' },
  { label: 'Unread', value: 'unread' },
  { label: 'Read', value: 'read' },
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
  general: Bell,
}
