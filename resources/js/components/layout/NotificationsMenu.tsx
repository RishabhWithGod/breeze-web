import { Link, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Bell } from 'lucide-react'
import { useClickOutside, useDisclosure } from '@/hooks'
import type { SharedPageProps } from '@/types'
import { formatRelative } from '@/utils'

/** Bell trigger with an unread counter and a dropdown of recent notifications. */
export function NotificationsMenu() {
  const { isOpen, close, toggle } = useDisclosure()
  const ref = useClickOutside<HTMLDivElement>(close, isOpen)
  const { notifications, unreadNotificationCount } = usePage<SharedPageProps>().props

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={toggle}
        aria-haspopup="menu"
        aria-expanded={isOpen}
        aria-label={`Notifications — ${unreadNotificationCount} unread`}
        className="relative grid size-10 place-items-center rounded-full text-white transition-colors hover:bg-white/10 hover:text-brand"
      >
        <Bell size={21} aria-hidden />
        {unreadNotificationCount > 0 && (
          <span className="absolute top-1 right-1 grid size-4 place-items-center rounded-full bg-status-danger text-[10px] font-bold text-white">
            {unreadNotificationCount}
          </span>
        )}
      </button>

      <AnimatePresence>
        {isOpen && (
          <motion.div
            role="menu"
            initial={{ opacity: 0, y: -8, scale: 0.97 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -8, scale: 0.97 }}
            transition={{ duration: 0.18 }}
            className="absolute right-0 z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-card border border-hairline-strong grad-ocean-solid shadow-raised"
          >
            <div className="flex items-center justify-between border-b border-hairline px-4 py-3">
              <p className="font-semibold text-white">Notifications</p>
              <span className="text-xs text-brand">{unreadNotificationCount} new</span>
            </div>

            <ul className="max-h-80 overflow-y-auto p-2">
              {notifications.map((notification) => {
                const body = (
                  <>
                    <p className="text-md font-medium text-white">{notification.title}</p>
                    <p className="mt-0.5 text-sm text-white/60">{notification.detail}</p>
                    <p className="mt-1 text-xs text-white/40">
                      {formatRelative(notification.timestamp)}
                    </p>
                  </>
                )
                const rowClass =
                  'block w-full rounded-panel px-3 py-3 text-left transition-colors hover:bg-white/8'

                return (
                  <li key={notification.id}>
                    {notification.link ? (
                      <Link
                        href={notification.link}
                        role="menuitem"
                        className={rowClass}
                        onClick={close}
                      >
                        {body}
                      </Link>
                    ) : (
                      <button type="button" role="menuitem" className={rowClass}>
                        {body}
                      </button>
                    )}
                  </li>
                )
              })}
            </ul>

            <div className="border-t border-hairline px-4 py-3 text-center">
              <button
                type="button"
                className="text-sm text-brand transition-colors hover:text-white"
              >
                View all notifications
              </button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
