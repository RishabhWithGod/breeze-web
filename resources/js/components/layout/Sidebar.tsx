import { useEffect, useState } from 'react'
import { ChevronDown } from 'lucide-react'
import { Link, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ROUTES, SIDEBAR_ITEMS } from '@/constants'
import { useUiStore } from '@/store'
import type { NavItem, SharedPageProps } from '@/types'
import { cn } from '@/utils'

interface SidebarNavProps {
  onNavigate: () => void
}

const ITEM_BASE =
  'group relative flex items-center gap-4 border-b border-steel-500/60 px-5 py-3.5 ' +
  'text-md font-medium transition-colors duration-200'

/**
 * The paths a module owns beyond the one its entry links to.
 *
 * A module's screens do not all live under its link. The takeoff flow runs
 * across `/processing`, `/reviews` and `/takeoffs` and none of those start with
 * `/ai-takeoff` — so before this, signing off a review lit nothing at all and
 * the rail stopped saying where you were half way through the flow.
 */
const MODULE_PATHS: Readonly<Record<string, readonly string[]>> = {
  [ROUTES.aiTakeoff]: ['/processing', '/results', '/reviews', '/takeoffs'],
  // The entry links to the entries list; the module also covers /week,
  // /reports and /settings.
  [ROUTES.timeTracking]: ['/time-tracking'],
  // The entry links straight to the invoices list, which is the module's
  // landing screen — but the overview at /billing is the same module.
  [ROUTES.billing]: ['/billing'],
  // The calendar is the module's entry; the rest of scheduling (the unassigned
  // list, availability) sits under /scheduling and is the same module.
  [ROUTES.schedulingCalendar]: ['/scheduling'],
}

/**
 * Screens whose module is not the one their URL sits under.
 *
 * The takeoff flow's later steps are not takeoff screens. Its job step lives at
 * `/takeoffs/{id}/final` and its task step at `/jobs/{id}/tasks/setup`, and the
 * rail has to name the step you are on — the same one the roadmap at the top of
 * those screens is pointing at. Matched exactly, so the drawing viewer at
 * `/takeoffs/{id}/pdf` stays where it belongs.
 */
const PATH_OVERRIDES: readonly { readonly pattern: RegExp; readonly href: string }[] = [
  { pattern: /^\/takeoffs\/\d+\/final$/, href: ROUTES.jobs },
  { pattern: /^\/jobs\/\d+\/tasks\/setup$/, href: ROUTES.tasks },
]

/** `/` is an alias of the home route, so both must light the Home entry. */
const HOME_PATHS: readonly string[] = ['/', ROUTES.home]

/**
 * How specifically an item claims this path, or -1 for not at all.
 *
 * A number rather than a yes/no because two entries can both be right and only
 * one may light: `/tasks/create` is claimed by "Tasks" and by "Add task", and
 * the longer claim is the screen you are actually on.
 */
function matchStrength(item: NavItem, pathname: string): number {
  if (item.href === ROUTES.home) return HOME_PATHS.includes(pathname) ? 1 : -1

  const owned = [item.href, ...(MODULE_PATHS[item.href] ?? [])]

  return owned.reduce(
    (best, path) => (pathname === path || pathname.startsWith(`${path}/`) ? Math.max(best, path.length) : best),
    -1,
  )
}

/** Every row in the rail, parents and their children alike — flattened once for matching. */
const ALL_ITEMS: readonly NavItem[] = SIDEBAR_ITEMS.flatMap((item) => [item, ...(item.children ?? [])])

/** The one entry to light up: whichever claims this path most specifically. */
function activeHref(pathname: string): string | null {
  const override = PATH_OVERRIDES.find(({ pattern }) => pattern.test(pathname))

  if (override) return override.href

  let best: { href: string; strength: number } | null = null

  for (const item of ALL_ITEMS) {
    const strength = matchStrength(item, pathname)

    if (strength >= 0 && (best === null || strength > best.strength)) {
      best = { href: item.href, strength }
    }
  }

  return best?.href ?? null
}

/** Whether a rail entry is shown to this role. */
const allowed = (
  item: NavItem,
  role: string,
  features: SharedPageProps['features'],
  permissions: SharedPageProps['permissions'],
): boolean =>
  (item.roles === undefined || item.roles.includes(role)) &&
  (item.feature === undefined || features[item.feature]) &&
  // What the role's permissions open (Roles & Permissions); null means the role is not governed by them.
  (item.permission === undefined || permissions === null || permissions.includes(item.permission))

/** Current path, without the query string. */
function usePathname(): string {
  const { url } = usePage()
  return url.split('?')[0] ?? url
}

interface SidebarLinkProps {
  item: NavItem
  isActive: boolean
  onNavigate?: () => void
  /** A child row, shown smaller and indented under its parent — see Estimates → Addendum. */
  nested?: boolean
  /** Leaves room on the right for a fold chevron drawn over the row. */
  padEnd?: boolean
}

/** One row of the rail — a plain link, or (when `nested`) a smaller, indented one under its parent. */
function SidebarLink({ item, isActive, onNavigate, nested = false, padEnd = false }: SidebarLinkProps) {
  return (
    <Link
      href={item.href}
      onClick={onNavigate}
      aria-current={isActive ? 'page' : undefined}
      className={cn(
        ITEM_BASE,
        'text-white',
        nested && 'py-2.5 pl-11 text-sm',
        padEnd && 'pr-14',
        isActive ? 'grad-midnight text-white' : 'hover:bg-white/8 hover:text-brand',
      )}
    >
      {isActive && <span className="absolute inset-y-0 left-0 w-1 bg-brand" aria-hidden />}
      <item.icon size={nested ? 16 : 20} aria-hidden className={cn('shrink-0', isActive && 'text-brand')} />
      <span className="truncate">{item.label}</span>
      {item.badge ? (
        <span className="ml-auto grid min-w-6 place-items-center rounded-full bg-status-danger px-1.5 py-0.5 text-2xs font-bold text-white">
          {item.badge}
        </span>
      ) : null}
    </Link>
  )
}

function SidebarNav({ onNavigate }: SidebarNavProps) {
  const pathname = usePathname()
  const active = activeHref(pathname)
  const { auth, features, permissions } = usePage<SharedPageProps>().props
  const role = (auth.user?.role ?? '').trim().toLowerCase()

  // A group its role cannot open still shows the entries under it that it can — an apprentice has
  // no Jobs, but has Tasks — as rows of their own.
  const visibleItems = SIDEBAR_ITEMS.flatMap((item): readonly NavItem[] =>
    allowed(item, role, features, permissions)
      ? [item]
      : (item.children ?? []).filter((child) => allowed(child, role, features, permissions)),
  )

  // Groups start open, as the reference draws them. Two different clicks:
  // the row is the whole tab — it opens the group's own screen and its submenu —
  // while the chevron only folds or opens the submenu and goes nowhere. A group
  // holding the page you are on stays open whatever was asked of it, so the rail
  // never hides where you are.
  const [folded, setFolded] = useState<ReadonlySet<string>>(new Set())

  const toggle = (label: string) =>
    setFolded((current) => {
      const next = new Set(current)

      if (!next.delete(label)) next.add(label)

      return next
    })

  /** Opening a group's own screen also opens its group — the row is the whole tab. */
  const unfold = (label: string) =>
    setFolded((current) => {
      if (!current.has(label)) return current

      const next = new Set(current)
      next.delete(label)

      return next
    })

  return (
    // `overscroll-contain` keeps a flick at the end of the list from scrolling
    // the page behind it; the bottom padding stops the last entry sitting hard
    // against the window edge once the rail is long enough to scroll.
    <nav aria-label="Main navigation" className="sidebar-scroll flex-1 overflow-y-auto overscroll-contain py-2 pb-6">
      <ul>
        {visibleItems.map((item: NavItem) => {
          // Some entries are for particular roles only.
          const children = (item.children ?? []).filter((child) => allowed(child, role, features, permissions))
          const holdsActive = children.some((child) => child.href === active)
          const isOpen = holdsActive || !folded.has(item.label)

          return (
            <li key={item.label}>
              <div className="relative">
                <SidebarLink
                  item={item}
                  isActive={item.href === active}
                  onNavigate={() => {
                    onNavigate()
                    if (children.length > 0) unfold(item.label)
                  }}
                  {...(children.length > 0 ? { padEnd: true } : {})}
                />
                {children.length > 0 && (
                  <button
                    type="button"
                    onClick={() => toggle(item.label)}
                    aria-expanded={isOpen}
                    aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${item.label}`}
                    disabled={holdsActive}
                    className="absolute top-1/2 right-3 grid size-8 -translate-y-1/2 place-items-center rounded-full text-white/85 transition-colors hover:bg-white/12 hover:text-white disabled:cursor-default disabled:hover:bg-transparent"
                  >
                    <ChevronDown
                      size={17}
                      aria-hidden
                      className={cn('transition-transform duration-200', !isOpen && '-rotate-90')}
                    />
                  </button>
                )}
              </div>

              {children.length > 0 && isOpen && (
                <ul>
                  {children.map((child) => (
                    <li key={child.label}>
                      <SidebarLink item={child} isActive={child.href === active} onNavigate={onNavigate} nested />
                    </li>
                  ))}
                </ul>
              )}
            </li>
          )
        })}
      </ul>
    </nav>
  )
}

/** Persistent rail on desktop, off-canvas drawer below `lg`. */
export function Sidebar() {
  const { isSidebarOpen, closeSidebar } = useUiStore()
  const pathname = usePathname()

  // Close the mobile drawer whenever the page changes.
  useEffect(() => {
    closeSidebar()
  }, [pathname, closeSidebar])

  return (
    <>
      {/* Desktop rail */}
      <aside className="fixed top-navbar bottom-0 left-0 z-40 hidden w-sidebar flex-col border-r border-steel-500/50 grad-sidebar backdrop-blur-xl lg:flex">
        <SidebarNav onNavigate={closeSidebar} />
      </aside>

      {/* Mobile / tablet drawer */}
      <AnimatePresence>
        {isSidebarOpen && (
          <>
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.2 }}
              onClick={closeSidebar}
              className="fixed inset-0 z-40 bg-navy-950/70 backdrop-blur-sm lg:hidden"
              aria-hidden
            />
            <motion.aside
              initial={{ x: '-100%' }}
              animate={{ x: 0 }}
              exit={{ x: '-100%' }}
              transition={{ type: 'spring', stiffness: 380, damping: 38 }}
              className="fixed top-navbar bottom-0 left-0 z-50 flex w-sidebar max-w-[85vw] flex-col border-r border-steel-500/50 grad-sidebar backdrop-blur-xl lg:hidden"
            >
              <SidebarNav onNavigate={closeSidebar} />
            </motion.aside>
          </>
        )}
      </AnimatePresence>
    </>
  )
}
