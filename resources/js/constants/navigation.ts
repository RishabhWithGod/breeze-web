import {
  Calculator,
  Briefcase,
  CalendarDays,
  // ChartColumn,
  ClipboardList,
  Clock,
  Contact,
  FilePen,
  FileStack,
  FileText,
  FolderKanban,
  Gauge,
  ReceiptText,
  Sparkles,
  Users,
  ListChecks,
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
    permission: 'clients.view',
    children: [
      { label: 'Projects', href: ROUTES.projects, icon: FolderKanban, permission: 'projects.view' },
      // The people who run the work, and technicians who signed up from the
      // mobile app — a pending-approval section at the top of this screen.
      { label: 'Teams', href: ROUTES.teams, icon: Users, permission: 'crew.view' },
    ],
  },
  // Lands on the takeoff history, matching the reference product.
  { label: 'AI Takeoff', href: ROUTES.aiTakeoff, icon: Sparkles, permission: 'takeoff.view' },
  {
    label: 'Estimates',
    href: ROUTES.estimates,
    icon: FileText,
    permission: 'estimates.view',
    children: [
      // Where quantities become priced labor and material lines — for the people who
      // price work: a project manager, an estimator or a supervisor.
      {
        label: 'Estimate Builder',
        href: ROUTES.estimateBuilder,
        icon: Calculator,
        permission: 'estimates.view',
        roles: ['project manager', 'estimator', 'supervisor', 'admin', 'owner'],
        feature: 'estimateBuilder',
      },
      // Extra scope raised against an existing estimate — its own screen.
      { label: 'Addendums', href: ROUTES.addenda, icon: FileStack, permission: 'estimates.view' },
      // What an estimate is priced from — the company's price book, kept as its commodity list.
      {
        label: 'Commodity List',
        href: ROUTES.commodities,
        icon: ListChecks,
        permission: 'estimates.view',
        roles: ['project manager', 'estimator', 'supervisor', 'admin', 'owner'],
      },
    ],
  },
  {
    label: 'Jobs',
    href: ROUTES.jobs,
    icon: Briefcase,
    permission: 'jobs.view',
    children: [
      // Read across every job rather than inside one. Adding a task is a button
      // on its list, the way every other module does it.
      { label: 'Tasks', href: ROUTES.tasks, icon: ClipboardList, permission: 'tasks.view' },
      // The crew calendar is the scheduling screen; it lives with the work it draws.
      { label: 'Calendar', href: ROUTES.schedulingCalendar, icon: CalendarDays, permission: 'schedule.view' },
      // Work added to a job after it began: raised by a foreman or the office, decided by a manager.
      {
        label: 'Change Orders',
        href: ROUTES.changeOrders,
        icon: FilePen,
        permission: 'change_orders.view',
        roles: ['project manager', 'foreman', 'admin', 'owner'],
      },
    ],
  },
  { label: 'Time Tracking', href: ROUTES.timeTracking, icon: Clock, permission: 'time_tracking.view' },
  { label: 'Billing', href: ROUTES.billing, icon: ReceiptText, permission: 'billing.view' },
  // { label: 'Analytics', href: ROUTES.jobCosting, icon: ChartColumn },
]

export const FOOTER_LINKS: readonly { label: string; href: string }[] = [
  { label: 'Documentation', href: '#' },
  { label: 'Release notes', href: '#' },
  { label: 'Privacy', href: '#' },
  { label: 'Terms', href: '#' },
  { label: 'Status', href: '#' },
]
