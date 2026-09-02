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
 * URL prefixes that belong to the AI Takeoff module. Processing and results are
 * per-project URLs now, so this matches on prefix rather than exact path.
 */
const TAKEOFF_PREFIXES: readonly string[] = [ROUTES.aiTakeoff, '/processing', '/results']

/** `/` is an alias of the home route, so both must light the Home entry. */
const HOME_PATHS: readonly string[] = ['/', ROUTES.home]

function isItemActive(item: NavItem, pathname: string): boolean {
  if (item.href === ROUTES.home) return HOME_PATHS.includes(pathname)
  if (item.href === ROUTES.aiTakeoff) {
    return TAKEOFF_PREFIXES.some((prefix) => pathname.startsWith(prefix))
  }
  // The item links straight to the entries list (the module's landing
  // screen), but the module also covers /week, /reports and /settings —
  // all of those should still light up "Time Tracking" in the sidebar.
  if (item.href === ROUTES.timeTracking) return pathname.startsWith('/time-tracking')
  return pathname === item.href || pathname.startsWith(`${item.href}/`)
}

/**
 * The one entry to light up, which is the *longest* href that matches.
 *
 * Prefix matching alone lit two rows at once now that one entry's href sits
 * under another's: `/tasks/create` matches both "Tasks" and "Add task". The
 * more specific entry is the one you are actually on.
 */
function activeHref(pathname: string): string | null {
  return SIDEBAR_ITEMS.reduce<string | null>(
    (best, item) =>
      isItemActive(item, pathname) && (best === null || item.href.length > best.length)
        ? item.href
        : best,
    null,
  )
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
