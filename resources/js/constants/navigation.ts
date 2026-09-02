import {
  Briefcase,
  CalendarCheck,
  ChartColumn,
  ClipboardList,
  Clock,
  FileText,
  FolderClosed,
  FolderKanban,
  Gauge,
  ReceiptText,
  Sparkles,
  Users,
} from 'lucide-react'
import { ROUTES } from './routes'
import type { NavItem } from '@/types'

/**
 * The full product drawer, in the reference application's order.
 *
 * Breeze Bucks is hidden for now — it still resolves to ModuleController's
 * "in development" screen — every other item is a real, built module.
 */
export const SIDEBAR_ITEMS: readonly NavItem[] = [
  { label: 'Dashboard', href: ROUTES.home, icon: Gauge },
  // Sits directly under Dashboard: a project is what every takeoff, estimate and
  // job is raised against.
  { label: 'Clients', href: ROUTES.projects, icon: FolderKanban },
  // Lands on the takeoff history, matching the reference product.
  { label: 'AI Takeoff', href: ROUTES.aiTakeoff, icon: Sparkles },
  { label: 'Estimates', href: ROUTES.estimates, icon: FileText },
  { label: 'Jobs', href: ROUTES.jobs, icon: Briefcase },
  // Work and the people who run it: both are read across every job rather than
  // inside one, so they follow Jobs as entries of their own. Only the lists are
  // here — adding one is a button on its list, the way every other module does
  // it, rather than a second drawer entry per screen.
  { label: 'Tasks', href: ROUTES.tasks, icon: ClipboardList },
  { label: 'Foremen', href: ROUTES.foremen, icon: Users },
  { label: 'Scheduling', href: ROUTES.scheduling, icon: CalendarCheck },
  { label: 'Time Tracking', href: ROUTES.timeTracking, icon: Clock },
  { label: 'Billing', href: ROUTES.billing, icon: ReceiptText },
  { label: 'Analytics', href: ROUTES.jobCosting, icon: ChartColumn },
  { label: 'Documents', href: ROUTES.documents, icon: FolderClosed },
]

export const FOOTER_LINKS: readonly { label: string; href: string }[] = [
  { label: 'Documentation', href: '#' },
  { label: 'Release notes', href: '#' },
  { label: 'Privacy', href: '#' },
  { label: 'Terms', href: '#' },
  { label: 'Status', href: '#' },
]
