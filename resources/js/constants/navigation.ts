import {
  BookOpen,
  Briefcase,
  CalendarDays,
  // ChartColumn,
  ClipboardList,
  Clock,
  Contact,
  FileStack,
  FileText,
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
  /*
   * Who the work is for, and then the work itself. A client has projects and a
   * crew; a project is what a drawing is taken off, and what every takeoff,
   * estimate and job is raised against.
   */
  {
    label: 'Clients',
    href: ROUTES.clients,
    icon: Contact,
    children: [
      { label: 'Projects', href: ROUTES.projects, icon: FolderKanban },
      // The people who run the work, and technicians who signed up from the
      // mobile app — a pending-approval section at the top of this screen.
      { label: 'Teams', href: ROUTES.teams, icon: Users },
    ],
  },
  // Lands on the takeoff history, matching the reference product.
  { label: 'AI Takeoff', href: ROUTES.aiTakeoff, icon: Sparkles },
  {
    label: 'Estimates',
    href: ROUTES.estimates,
    icon: FileText,
    children: [
      // Extra scope raised against an existing estimate — its own screen.
      { label: 'Addendums', href: ROUTES.addenda, icon: FileStack },
      // What an estimate is priced from, so it sits beside them.
      { label: 'Price Book', href: ROUTES.priceBook, icon: BookOpen },
    ],
  },
  {
    label: 'Jobs',
    href: ROUTES.jobs,
    icon: Briefcase,
    children: [
      // Read across every job rather than inside one. Adding a task is a button
      // on its list, the way every other module does it.
      { label: 'Tasks', href: ROUTES.tasks, icon: ClipboardList },
      // The crew calendar is the scheduling screen; it lives with the work it draws.
      { label: 'Calendar', href: ROUTES.schedulingCalendar, icon: CalendarDays },
    ],
  },
  { label: 'Time Tracking', href: ROUTES.timeTracking, icon: Clock },
  { label: 'Billing', href: ROUTES.billing, icon: ReceiptText },
  // { label: 'Analytics', href: ROUTES.jobCosting, icon: ChartColumn },
]

export const FOOTER_LINKS: readonly { label: string; href: string }[] = [
  { label: 'Documentation', href: '#' },
  { label: 'Release notes', href: '#' },
  { label: 'Privacy', href: '#' },
  { label: 'Terms', href: '#' },
  { label: 'Status', href: '#' },
]
