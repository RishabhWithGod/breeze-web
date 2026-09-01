import { Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Bell, ChevronDown, LogOut, Settings, ShieldCheck, User, type LucideIcon } from 'lucide-react'
import { ROUTES } from '@/constants'
import { useClickOutside, useDisclosure } from '@/hooks'
import type { SharedPageProps } from '@/types'

const ACCOUNT_ITEMS: readonly { label: string; icon: LucideIcon; href: string }[] = [
  { label: 'Profile', icon: User, href: ROUTES.profile },
  { label: 'Notifications', icon: Bell, href: ROUTES.notifications },
  { label: 'Security', icon: ShieldCheck, href: ROUTES.security },
  { label: 'Settings', icon: Settings, href: ROUTES.settings },
]

/** Avatar + name trigger with account actions and sign-out. */
export function ProfileMenu() {
  const { isOpen, close, toggle } = useDisclosure()
  const ref = useClickOutside<HTMLDivElement>(close, isOpen)
  const user = usePage<SharedPageProps>().props.auth.user

  if (!user) return null

  const handleLogout = () => {
    close()
    // POST, so signing out can't be triggered by a prefetch or a stray GET.
    router.post(ROUTES.logout)
  }

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={toggle}
        aria-haspopup="menu"
        aria-expanded={isOpen}
        className="flex items-center gap-3 rounded-pill py-1 pr-2 pl-1 transition-colors hover:bg-white/10"
      >
        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-ocean-800 text-md font-semibold text-white ring-1 ring-steel-600">
          {user.initials}
        </span>
        <span className="hidden text-left leading-tight lg:block">
          <span className="block text-md font-medium text-white">{user.name}</span>
          <span className="block text-xs text-white/80">{user.role}</span>
        </span>
        <ChevronDown size={16} aria-hidden className="hidden text-white/85 lg:block" />
      </button>

      <AnimatePresence>
        {isOpen && (
          <motion.div
            role="menu"
            initial={{ opacity: 0, y: -8, scale: 0.97 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -8, scale: 0.97 }}
            transition={{ duration: 0.18 }}
            className="absolute right-0 z-50 mt-2 w-60 overflow-hidden rounded-card border border-hairline-strong grad-ocean-solid shadow-raised"
          >
            <div className="border-b border-hairline px-4 py-3">
              <p className="truncate text-md font-semibold text-white">{user.name}</p>
              <p className="truncate text-xs text-white/80">{user.email}</p>
            </div>

            <ul className="p-2">
              {ACCOUNT_ITEMS.map(({ label, icon: Icon, href }) => (
                <li key={label}>
                  <Link
                    href={href}
                    onClick={close}
                    role="menuitem"
                    className="flex w-full items-center gap-3 rounded-panel px-3 py-2.5 text-md text-white transition-colors hover:bg-white/10 hover:text-brand"
                  >
                    <Icon size={17} aria-hidden />
                    {label}
                  </Link>
                </li>
              ))}

              <li className="mt-1 border-t border-hairline pt-1">
                <button
                  type="button"
                  role="menuitem"
                  onClick={handleLogout}
                  className="flex w-full items-center gap-3 rounded-panel px-3 py-2.5 text-md text-white transition-colors hover:bg-status-danger/15 hover:text-red-200"
                >
                  <LogOut size={17} aria-hidden />
                  Logout
                </button>
              </li>
            </ul>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
