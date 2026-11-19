import {
  Calculator,
  CalendarDays,
  CalendarOff,
  Clock,
  FileStack,
  FolderKanban,
  BarChart3,
  Users,
  ListChecks,
  ShieldCheck,
  Settings,
  Zap,
  ScanLine,
  HardHat,
  FileDiff,
  Receipt,
  Building2,
  LayoutDashboard,
  Package,
} from 'lucide-react'
import { ROUTES } from './routes'
import type { NavItem } from '@/types'

/**
 * The full product drawer, in the reference application's order.
 *
 * Breeze Bucks is hidden for now — it still resolves to ModuleController's
 * "in development" screen — every other item is a real, built module.
 */
const NAV_ITEMS: readonly NavItem[] = [
  { label: 'Dashboard', href: ROUTES.home, icon: LayoutDashboard },
  /*
   * Who the work is for, and then the work itself. A client has projects and a
   * crew; a project is what a drawing is taken off, and what every takeoff,
   * estimate and job is raised against.
   */
  {
    label: 'Clients',
    href: ROUTES.clients,
    icon: Building2,
    permission: 'clients.view',
    children: [
      { label: 'Projects', href: ROUTES.projects, icon: FolderKanban, permission: 'projects.view' },
    ],
  },
  // Lands on the takeoff history, matching the reference product.
  { label: 'AI Takeoff', href: ROUTES.aiTakeoff, icon: ScanLine, permission: 'takeoff.view' },
  {
    label: 'Estimates',
    href: ROUTES.estimates,
    icon: Calculator,
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
      },
      // Extra scope raised against an existing estimate — its own screen.
      { label: 'Addendums', href: ROUTES.addenda, icon: FileStack, permission: 'estimates.view' },
      // What an estimate is priced from — the company's price book, kept as its commodity list.
      {
        label: 'Commodity List',
        href: ROUTES.commodities,
        icon: Package,
        permission: 'estimates.view',
        roles: ['project manager', 'estimator', 'supervisor', 'admin', 'owner'],
      },
    ],
  },
  {
    label: 'Jobs',
    href: ROUTES.jobs,
    icon: HardHat,
    permission: 'jobs.view',
    children: [
      // Read across every job rather than inside one. Adding a task is a button
      // on its list, the way every other module does it.
      { label: 'Tasks', href: ROUTES.tasks, icon: ListChecks, permission: 'tasks.view' },
      // The crew calendar is the scheduling screen; it lives with the work it draws.
      { label: 'Calendar', href: ROUTES.schedulingCalendar, icon: CalendarDays, permission: 'schedule.view' },
      // Work added to a job after it began: raised by a foreman or the office, decided by a manager.
      {
        label: 'Change Orders',
        href: ROUTES.changeOrders,
        icon: FileDiff,
        permission: 'change_orders.view',
        roles: ['project manager', 'foreman', 'admin', 'owner'],
      },
      // Hidden from the sidebar for now; the page itself is still routed.
      // Field edits that clashed with an office edit, sent up from a phone for a manager to decide.
      // {
      //   label: 'Sync Conflicts',
      //   href: ROUTES.syncConflicts,
      //   icon: GitMerge,
      //   permission: 'jobs.view',
      //   roles: ['project manager', 'admin', 'owner'],
      // },
    ],
  },
  // A static preview of the calculation tools, ahead of the real engine.
  { label: 'Electrical Tools', href: ROUTES.electricalTools, icon: Zap },
  { label: 'Time Tracking', href: ROUTES.timeTracking, icon: Clock, permission: 'time_tracking.view' },
  { label: 'Billing', href: ROUTES.billing, icon: Receipt, permission: 'billing.view' },
  { label: 'Analytics', href: ROUTES.jobCosting, icon: BarChart3 },
  /*
   * The company itself — its people, what each role may do, and its own account. These sat under
   * Clients (Teams, Leave) or in the profile menu (Settings); they are grouped as "Company" in the rail,
   * as in the Breeze app.
   */
  {
    label: 'Teams',
    href: ROUTES.teams,
    icon: Users,
    permission: 'crew.view',
    children: [
      // Time off the crew asked for in the field app — approved or turned down here.
      {
        label: 'Leave',
        href: ROUTES.leave,
        icon: CalendarOff,
        permission: 'crew.view',
        roles: ['project manager', 'admin', 'owner'],
      },
    ],
  },
  { label: 'Roles & Permissions', href: ROUTES.rolesPermissions, icon: ShieldCheck, permission: 'admin.manage_roles' },
  { label: 'Settings', href: ROUTES.settings, icon: Settings },
]

export interface SidebarGroup {
  /** The small heading above the group; unset for the lone Dashboard entry. */
  readonly label?: string
  readonly items: readonly NavItem[]
}

const pick = (...labels: readonly string[]): readonly NavItem[] =>
  labels.flatMap((label) => NAV_ITEMS.filter((item) => item.label === label))

const GROUPED: readonly SidebarGroup[] = [
  { items: pick('Dashboard') },
  // Who the work is for, and what gets priced before it is won.
  { label: 'Preconstruction', items: pick('Clients', 'AI Takeoff', 'Estimates') },
  // The work itself, and the hours spent on it.
  { label: 'Field operations', items: pick('Jobs', 'Time Tracking') },
  { label: 'Finance', items: pick('Billing', 'Analytics') },
  { label: 'Resources', items: pick('Electrical Tools') },
  // The company's own people and account.
  { label: 'Company', items: pick('Teams', 'Roles & Permissions', 'Settings') },
]

/**
 * The drawer, divided by category as in the Breeze app. Any entry not placed in
 * a group above still shows, in a trailing unlabelled group, so adding an item to
 * `NAV_ITEMS` can never make it vanish from the rail.
 */
export const SIDEBAR_GROUPS: readonly SidebarGroup[] = (() => {
  const placed = new Set(GROUPED.flatMap((group) => group.items))
  const rest = NAV_ITEMS.filter((item) => !placed.has(item))

  return rest.length > 0 ? [...GROUPED, { items: rest }] : GROUPED
})()

/** Every top-level entry, flat — for code that matches a path to its entry. */
export const SIDEBAR_ITEMS: readonly NavItem[] = SIDEBAR_GROUPS.flatMap((group) => group.items)

export const FOOTER_LINKS: readonly { label: string; href: string }[] = [
  { label: 'Documentation', href: '#' },
  { label: 'Release notes', href: '#' },
  { label: 'Privacy', href: '#' },
  { label: 'Terms', href: '#' },
]
