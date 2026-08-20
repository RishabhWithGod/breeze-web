import {
  Bell,
  Briefcase,
  CalendarCheck,
  ChartColumn,
  Clock,
  Coins,
  FileText,
  FolderClosed,
  FolderKanban,
  Gauge,
  ReceiptText,
  Settings,
  ShieldCheck,
  Sparkles,
} from 'lucide-react'
import { ROUTES } from './routes'
import type { NavItem } from '@/types'

/**
 * The full product drawer, in the reference application's order.
 *
 * Only Breeze Bucks still resolves to ModuleController's "in development"
 * screen — every other item is a real, built module.
 */
export const SIDEBAR_ITEMS: readonly NavItem[] = [
  { label: 'Dashboard', href: ROUTES.home, icon: Gauge },
  // Sits directly under Dashboard: a project is what every takeoff, estimate and
  // job is raised against.
  { label: 'Projects', href: ROUTES.projects, icon: FolderKanban },
  // Lands on the takeoff history, matching the reference product.
  { label: 'AI Takeoff', href: ROUTES.aiTakeoff, icon: Sparkles },
  { label: 'Estimates', href: ROUTES.estimates, icon: FileText },
  { label: 'Jobs', href: ROUTES.jobs, icon: Briefcase },
  { label: 'Scheduling', href: ROUTES.scheduling, icon: CalendarCheck },
  { label: 'Time Tracking', href: ROUTES.timeTracking, icon: Clock },
  { label: 'Billing', href: ROUTES.billing, icon: ReceiptText },
  { label: 'Job Costing', href: ROUTES.jobCosting, icon: ChartColumn },
  { label: 'Documents', href: ROUTES.documents, icon: FolderClosed },
  { label: 'Notifications', href: ROUTES.notifications, icon: Bell },
  { label: 'Settings', href: ROUTES.settings, icon: Settings },
  { label: 'Security', href: ROUTES.security, icon: ShieldCheck },
  { label: 'Breeze Bucks', href: ROUTES.breezeBucks, icon: Coins },
]

export const FOOTER_LINKS: readonly { label: string; href: string }[] = [
  { label: 'Documentation', href: '#' },
  { label: 'Release notes', href: '#' },
  { label: 'Privacy', href: '#' },
  { label: 'Terms', href: '#' },
  { label: 'Status', href: '#' },
]
