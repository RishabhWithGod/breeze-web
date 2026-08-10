import { useEffect } from 'react'
import { Link, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { PanelLeftClose, PanelLeftOpen, Sparkles } from 'lucide-react'
import { ROUTES, SIDEBAR_ITEMS } from '@/constants'
import { useUiStore } from '@/store'
import type { NavItem } from '@/types'
import { cn } from '@/utils'

interface SidebarNavProps {
  isCollapsed: boolean
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
  return pathname === item.href || pathname.startsWith(`${item.href}/`)
}

/** Current path, without the query string. */
function usePathname(): string {
  const { url } = usePage()
  return url.split('?')[0] ?? url
}

function SidebarNav({ isCollapsed, onNavigate }: SidebarNavProps) {
  const pathname = usePathname()

  return (
    <nav aria-label="Main navigation" className="flex-1 overflow-y-auto py-2">
      <ul>
        {SIDEBAR_ITEMS.map((item: NavItem) => {
          const isActive = isItemActive(item, pathname)

          return (
            <li key={item.label}>
              <Link
                href={item.href}
                onClick={onNavigate}
                aria-current={isActive ? 'page' : undefined}
                title={isCollapsed ? item.label : undefined}
                className={cn(
                  ITEM_BASE,
                  'text-white',
                  isCollapsed && 'justify-center px-0',
                  isActive
                    ? 'grad-midnight text-white'
                    : 'hover:bg-white/8 hover:text-brand',
                )}
              >
                {isActive && (
                  <span className="absolute inset-y-0 left-0 w-1 bg-brand" aria-hidden />
                )}
                <item.icon
                  size={20}
                  aria-hidden
                  className={cn('shrink-0', isActive && 'text-brand')}
                />
                {!isCollapsed && <span className="truncate">{item.label}</span>}
                {!isCollapsed && item.badge ? (
                  <span className="ml-auto grid min-w-6 place-items-center rounded-full bg-status-danger px-1.5 py-0.5 text-2xs font-bold text-white">
                    {item.badge}
                  </span>
                ) : null}
              </Link>
            </li>
          )
        })}
      </ul>
    </nav>
  )
}

function SidebarPromo() {
  return (
    <div className="m-4 rounded-card border border-brand/30 bg-brand/10 p-4">
      <div className="mb-2 flex items-center gap-2 text-brand">
        <Sparkles size={16} aria-hidden />
        <p className="text-sm font-semibold">AI credits</p>
      </div>
      <p className="text-xs text-white/90">
        18 of 25 takeoffs used this month. Resets on the 1st.
      </p>
      <div className="mt-3 h-1.5 overflow-hidden rounded-pill bg-white/20">
        <div className="h-full w-[72%] rounded-pill bg-brand" />
      </div>
    </div>
  )
}

/**
 * Persistent rail on desktop, off-canvas drawer below `lg`. Collapsing is a
 * desktop-only affordance held in the UI store.
 */
export function Sidebar() {
  const { isSidebarOpen, closeSidebar, isSidebarCollapsed, toggleSidebarCollapsed } =
    useUiStore()
  const pathname = usePathname()

  // Close the mobile drawer whenever the page changes.
  useEffect(() => {
    closeSidebar()
  }, [pathname, closeSidebar])

  return (
    <>
      {/* Desktop rail */}
      <aside
        className={cn(
          'fixed top-navbar bottom-0 left-0 z-40 hidden flex-col border-r border-steel-500/50 grad-sidebar backdrop-blur-xl lg:flex',
          'transition-[width] duration-300 ease-out',
          isSidebarCollapsed ? 'w-20' : 'w-sidebar',
        )}
      >
        <SidebarNav isCollapsed={isSidebarCollapsed} onNavigate={closeSidebar} />
        {!isSidebarCollapsed && <SidebarPromo />}

        <button
          type="button"
          onClick={toggleSidebarCollapsed}
          aria-label={isSidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          className="flex items-center gap-3 border-t border-steel-500/60 px-5 py-3.5 text-sm text-white/90 transition-colors hover:bg-white/8 hover:text-brand"
        >
          {isSidebarCollapsed ? (
            <PanelLeftOpen size={18} aria-hidden className="mx-auto" />
          ) : (
            <>
              <PanelLeftClose size={18} aria-hidden />
              Collapse
            </>
          )}
        </button>
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
              <SidebarNav isCollapsed={false} onNavigate={closeSidebar} />
              <SidebarPromo />
            </motion.aside>
          </>
        )}
      </AnimatePresence>
    </>
  )
}
