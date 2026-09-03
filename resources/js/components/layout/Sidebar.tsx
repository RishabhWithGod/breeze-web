import { useEffect } from 'react'
import { Link, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ROUTES, SIDEBAR_ITEMS } from '@/constants'
import { useUiStore } from '@/store'
import type { NavItem } from '@/types'
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
    (best, path) =>
      pathname === path || pathname.startsWith(`${path}/`)
        ? Math.max(best, path.length)
        : best,
    -1,
  )
}

/** The one entry to light up: whichever claims this path most specifically. */
function activeHref(pathname: string): string | null {
  const override = PATH_OVERRIDES.find(({ pattern }) => pattern.test(pathname))

  if (override) return override.href

  let best: { href: string; strength: number } | null = null

  for (const item of SIDEBAR_ITEMS) {
    const strength = matchStrength(item, pathname)

    if (strength >= 0 && (best === null || strength > best.strength)) {
      best = { href: item.href, strength }
    }
  }

  return best?.href ?? null
}

/** Current path, without the query string. */
function usePathname(): string {
  const { url } = usePage()
  return url.split('?')[0] ?? url
}

interface SidebarLinkProps {
  item: NavItem
  isActive: boolean
  onNavigate?: () => void
}

/** One row of the rail. Every entry is the same row — no nesting, no variants. */
function SidebarLink({ item, isActive, onNavigate }: SidebarLinkProps) {
  return (
    <Link
      href={item.href}
      onClick={onNavigate}
      aria-current={isActive ? 'page' : undefined}
      className={cn(
        ITEM_BASE,
        'text-white',
        isActive ? 'grad-midnight text-white' : 'hover:bg-white/8 hover:text-brand',
      )}
    >
      {isActive && <span className="absolute inset-y-0 left-0 w-1 bg-brand" aria-hidden />}
      <item.icon size={20} aria-hidden className={cn('shrink-0', isActive && 'text-brand')} />
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

  return (
    // `overscroll-contain` keeps a flick at the end of the list from scrolling
    // the page behind it; the bottom padding stops the last entry sitting hard
    // against the window edge once the rail is long enough to scroll.
    <nav
      aria-label="Main navigation"
      className="sidebar-scroll flex-1 overflow-y-auto overscroll-contain py-2 pb-6"
    >
      <ul>
        {SIDEBAR_ITEMS.map((item: NavItem) => (
          <li key={item.label}>
            <SidebarLink
              item={item}
              isActive={item.href === active}
              onNavigate={onNavigate}
            />
          </li>
        ))}
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
