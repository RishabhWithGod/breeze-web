import { useEffect, useRef, useState } from 'react'
import { ChevronDown } from 'lucide-react'
import { Link, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ROUTES, SIDEBAR_GROUPS, SIDEBAR_ITEMS } from '@/constants'
import { useUiStore } from '@/store'
import type { NavItem, SharedPageProps } from '@/types'
import { cn } from '@/utils'
import { CompanyMark } from './CompanyMark'
import { Logo } from './Logo'

interface SidebarNavProps {
  onNavigate: () => void
}

const ITEM_BASE =
  'group relative flex items-center gap-[11px] rounded-lg px-2.5 py-2 ' +
  'text-[14px] font-medium transition-colors duration-150'

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
  isOwner: boolean,
  permissions: SharedPageProps['permissions'],
): boolean =>
  (item.roles === undefined || isOwner || item.roles.includes(role)) &&
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
      onClick={() => {
        // Remembered for the screen this opens: it was reached from the rail, so it needs no "Back".
        try {
          sessionStorage.setItem('breeze.cameFromSidebar', item.href)
        } catch {
          /* storage blocked */
        }
        onNavigate?.()
      }}
      aria-current={isActive ? 'page' : undefined}
      className={cn(
        'nav-link',
        ITEM_BASE,
        nested && 'py-1.5 pl-9 text-sm',
        padEnd && 'pr-12',
        isActive
          ? 'bg-linear-to-r from-accent-bright/16 to-accent-bright/2 text-white'
          : 'text-[#b9c6e2] hover:bg-white/6 hover:text-white',
      )}
    >
      {isActive && (
        <span
          className="absolute top-2 bottom-2 -left-2.5 w-[3px] rounded-r-[3px] bg-accent-bright shadow-[0_0_12px_rgb(62_230_255/0.8)]"
          aria-hidden
        />
      )}
      <item.icon
        size={nested ? 15 : 18}
        aria-hidden
        className={cn(
          'shrink-0 transition-colors',
          isActive
            ? 'text-accent-bright drop-shadow-[0_0_6px_rgb(62_230_255/0.6)]'
            : 'text-[#6f81a8] group-hover:text-[#dfe8ff]',
        )}
      />
      <span className="truncate">{item.label}</span>
      {item.badge ? (
        <span className="ml-auto grid min-w-6 place-items-center rounded-full bg-linear-to-br from-[#ffcf5a] to-[#f59e0b] px-1.5 py-0.5 text-2xs font-bold text-[#2a1700] shadow-[0_0_10px_rgb(245_158_11/0.35)]">
          {item.badge > 99 ? '99+' : item.badge}
        </span>
      ) : null}
    </Link>
  )
}

/** Which sidebar rows carry a count, and which shared figure feeds each. */
const BADGE_KEY = { 'AI Takeoff': 'takeoff', 'Change Orders': 'changeOrders', 'Time Tracking': 'timeEntries' } as const

function SidebarNav({ onNavigate }: SidebarNavProps) {
  const pathname = usePathname()
  const active = activeHref(pathname)
  const { auth, permissions, sidebarCounts } = usePage<SharedPageProps>().props
  const withBadge = (item: NavItem): NavItem => {
    const key = BADGE_KEY[item.label as keyof typeof BADGE_KEY]
    const count = key && sidebarCounts ? sidebarCounts[key] : 0

    return count > 0 ? { ...item, badge: count } : item
  }
  const role = (auth.user?.role ?? '').trim().toLowerCase()
  const isOwner = auth.user?.isOwner === true

  // A group its role cannot open still shows the entries under it that it can — an apprentice has
  // no Jobs, but has Tasks — as rows of their own.
  const visibleGroups = SIDEBAR_GROUPS.map((group) => ({
    label: group.label,
    items: group.items.flatMap((item): readonly NavItem[] =>
      allowed(item, role, isOwner, permissions)
        ? [withBadge({ ...item, children: item.children?.map(withBadge) })]
        : (item.children ?? []).filter((child) => allowed(child, role, isOwner, permissions)).map(withBadge),
    ),
  })).filter((group) => group.items.length > 0)

  // Groups start open, as the reference draws them. Two different clicks:
  // the row is the whole tab — it opens the group's own screen and its submenu —
  // while the chevron only folds or opens the submenu and goes nowhere. A group
  // holding the page you are on stays open whatever was asked of it, so the rail
  // never hides where you are.
  const [folded, setFolded] = useState<ReadonlySet<string>>(new Set())

  // Whatever page is open, the rail scrolls itself to its row — on a long rail the current tab
  // would otherwise sit out of view below the fold, and a page change (or a fresh mount) resets
  // nothing in the user's favour. `nearest` leaves the rail alone when the row is already visible.
  const navRef = useRef<HTMLElement>(null)

  useEffect(() => {
    const row = navRef.current?.querySelector<HTMLElement>('[aria-current="page"]')

    row?.scrollIntoView({ block: 'nearest' })
  }, [active])

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
    <nav ref={navRef} aria-label="Main navigation" className="sidebar-scroll flex-1 overflow-y-auto overscroll-contain px-2.5 py-2 pb-6">
      {visibleGroups.map((group, groupIndex) => (
        <div key={group.label ?? `group-${groupIndex}`} className="pt-2 pb-1">
          {group.label && (
            <p className="px-2.5 pt-1.5 pb-1 text-[11px] font-semibold tracking-[0.04em] text-[#93a4c8] uppercase">
              {group.label}
            </p>
          )}
          <ul>
            {group.items.map((item: NavItem) => {
              // Some entries are for particular roles only.
              const children = (item.children ?? []).filter((child) => allowed(child, role, isOwner, permissions))
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
                        className="absolute top-1/2 right-2 grid size-8 -translate-y-1/2 place-items-center rounded-full text-[#b9c6e2] transition-colors hover:bg-white/10 hover:text-white disabled:cursor-default disabled:hover:bg-transparent"
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
        </div>
      ))}
    </nav>
  )
}

/** Logo + company card that head the rail, as in the Breeze app. */
function SidebarHeader() {
  const { company } = usePage<SharedPageProps>().props

  return (
    <div className="shrink-0">
      <div className="px-5 pt-[22px] pb-2.5">
        <Logo />
      </div>
      {company && (
        <div className="mx-3 mt-1.5 mb-2.5 flex items-center gap-2.5 rounded-xl border border-[rgb(126_170_255/0.12)] bg-white/4 px-3 py-2.5">
          <CompanyMark
            logoUrl={company.logoUrl}
            name={company.name}
            size={30}
            className="rounded-full ring-2 ring-accent-bright/25"
          />
          <div className="min-w-0">
            <p className="truncate text-[13px] font-bold text-white">{company.name}</p>
            <p className="truncate text-[11.5px] text-[#93a4c8]">Workspace</p>
          </div>
        </div>
      )}
    </div>
  )
}

/** Small mono footer line, like the app's rail. */
function SidebarFoot() {
  return (
    <div className="shrink-0 border-t border-[rgb(126_170_255/0.1)] px-3 py-3 font-mono text-[11.5px] text-[#93a4c8]">
      Breeze.Ai · Smarter Software. Stronger Projects.
    </div>
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
      <aside className="fixed top-0 bottom-0 left-0 z-40 hidden w-sidebar flex-col border-r border-[rgb(126_170_255/0.08)] grad-sidebar lg:flex">
        <SidebarHeader />
        <SidebarNav onNavigate={closeSidebar} />
        <SidebarFoot />
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
              className="fixed inset-0 z-65 bg-navy-950/70 backdrop-blur-sm lg:hidden"
              aria-hidden
            />
            <motion.aside
              initial={{ x: '-100%' }}
              animate={{ x: 0 }}
              exit={{ x: '-100%' }}
              transition={{ type: 'spring', stiffness: 380, damping: 38 }}
              className="fixed top-0 bottom-0 left-0 z-70 flex w-sidebar max-w-[85vw] flex-col border-r border-[rgb(126_170_255/0.08)] grad-sidebar lg:hidden"
            >
              <SidebarHeader />
              <SidebarNav onNavigate={closeSidebar} />
              <SidebarFoot />
            </motion.aside>
          </>
        )}
      </AnimatePresence>
    </>
  )
}
