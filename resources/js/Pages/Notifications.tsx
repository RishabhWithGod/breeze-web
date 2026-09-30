import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Bell, ChevronDown, ChevronRight, Filter, MailOpen, SearchX } from 'lucide-react'
import { Alert, EmptyState, Pagination, SearchBox } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  NOTIFICATION_CATEGORY_ICON,
  NOTIFICATION_CATEGORY_TONE,
  NOTIFICATION_TABS,
  ROUTES,
  routeTo,
} from '@/constants'
import type {
  AppNotification,
  NotificationCategoryOption,
  NotificationFilters,
  NotificationTab,
  NotificationTabCounts,
  Paginated,
  SharedPageProps,
} from '@/types'
import { cn, formatRelative } from '@/utils'

export interface NotificationsProps {
  notifications: Paginated<AppNotification>
  filters: NotificationFilters
  tabCounts: NotificationTabCounts
  categories: readonly NotificationCategoryOption[]
}

/**
 * Notifications — everything the signed-in user has been sent, real and
 * database-backed. Opening one marks it read in the same request that takes them
 * where it points, so the header bell's unread count is always in step with this
 * list rather than a client-side guess.
 */
export default function Notifications({ notifications, filters, tabCounts, categories }: NotificationsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [query, setQuery] = useState(filters.search)

  const notice = flash.success ?? null
  const displayNotice = notice === dismissed ? null : notice

  const rows = notifications.data
  const { meta } = notifications
  const categoryLabel = (value: string) => categories.find((option) => option.value === value)?.label ?? value

  const applyFilters = useCallback((changes: Partial<NotificationFilters & { page: number }>) => {
    const params = new URLSearchParams(window.location.search)

    for (const [key, value] of Object.entries(changes)) {
      if (value === '' || value === 'all' || value === null || value === undefined) {
        params.delete(key)
      } else {
        params.set(key, String(value))
      }
    }

    if (!('page' in changes)) params.delete('page')

    const queryString = params.toString()

    router.get(queryString ? `${ROUTES.notifications}?${queryString}` : ROUTES.notifications, {}, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }, [])

  const markAllRead = () => {
    router.post(ROUTES.notificationsReadAll, {}, { preserveScroll: true })
  }

  /** Marks it read and, in the same request, carries on to where it points. */
  const open = (notification: AppNotification, href?: string) => {
    router.post(routeTo.notificationRead(notification.id), href ? { redirect: href } : {}, { preserveScroll: true })
  }

  return (
    <PageTransition>
      <Head title="Notifications" />

      <PageHeader
        title="Notifications"
        subtitle="Stay up to date with activity across your projects, estimates, and jobs."
      />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert key={displayNotice} tone="success" className="mb-5" onDismiss={() => setDismissed(displayNotice)}>
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      {/* =============================================== Tabs + tools ======== */}
      <div className="mb-5 flex flex-wrap items-center justify-between gap-4">
        <div
          role="tablist"
          aria-label="Notification views"
          className="inline-flex gap-1 rounded-card border border-hairline bg-white/5 p-1"
        >
          {NOTIFICATION_TABS.map((tab) => {
            const active = filters.tab === tab.value
            const count = tab.value === 'all' ? 0 : tabCounts[tab.value]

            return (
              <button
                key={tab.value}
                type="button"
                role="tab"
                aria-selected={active}
                onClick={() => applyFilters({ tab: tab.value as NotificationTab })}
                className={cn(
                  'inline-flex items-center gap-2 rounded-panel border px-5 py-2.5 text-md font-semibold transition-colors',
                  active
                    ? 'border-brand/70 bg-brand/12 text-white shadow-glow'
                    : 'border-transparent text-white/85 hover:bg-white/8',
                )}
              >
                {tab.label}
                {count > 0 && (
                  <span className="rounded-md border border-status-blue/40 bg-status-blue/15 px-2 py-0.5 text-xs text-status-blue">
                    {count}
                  </span>
                )}
              </button>
            )
          })}
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <SearchBox
            id="notification-search"
            value={query}
            onValueChange={setQuery}
            onSearch={(value) => applyFilters({ search: value })}
            placeholder="Search notifications..."
            aria-label="Search notifications"
            containerClassName="w-full sm:w-72"
          />

          <label className="relative inline-flex items-center">
            <span className="sr-only">Filter by type</span>
            <Filter size={16} aria-hidden className="pointer-events-none absolute left-4 text-white/85" />
            <select
              aria-label="Filter by type"
              value={filters.category}
              onChange={(event) => applyFilters({ category: event.target.value })}
              className="cursor-pointer appearance-none rounded-panel border border-hairline-strong bg-white/8 py-3 pr-10 pl-11 text-md font-medium text-white focus:border-brand focus:outline-none"
            >
              <option value="all" className="bg-navy-900">
                Filter
              </option>
              {categories.map((option) => (
                <option key={option.value} value={option.value} className="bg-navy-900">
                  {option.label}
                </option>
              ))}
            </select>
            <ChevronDown size={16} aria-hidden className="pointer-events-none absolute right-4 text-white/85" />
          </label>

          <button
            type="button"
            onClick={markAllRead}
            disabled={tabCounts.unread === 0}
            className="inline-flex items-center gap-2 rounded-panel border border-status-blue/50 bg-status-blue/15 px-5 py-3 text-md font-semibold whitespace-nowrap text-white transition-colors hover:bg-status-blue/30 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <MailOpen size={17} aria-hidden />
            Mark All Read
          </button>
        </div>
      </div>

      {/* ================================================== The list ========= */}
      {rows.length === 0 ? (
        <div className="rounded-card border border-hairline glass p-6">
          <EmptyState
            icon={SearchX}
            title={emptyTitle(filters)}
            description="Notifications from Jobs, Estimates, Calendar, AI Takeoff, Time Tracking, Documents, Billing and Job Costing all appear here."
          />
        </div>
      ) : (
        <ul className="space-y-2.5">
          <AnimatePresence initial={false}>
            {rows.map((notification) => (
              <NotificationCard
                key={notification.id}
                notification={notification}
                categoryLabel={categoryLabel(notification.category)}
                onOpen={open}
              />
            ))}
          </AnimatePresence>
        </ul>
      )}

      <Pagination
        withLabels
        className="mt-6"
        page={meta.current_page}
        pageCount={meta.last_page}
        onPageChange={(page) => applyFilters({ page })}
        summary={meta.total === 0 ? 'No notifications to display' : `Showing ${rows.length} of ${meta.total} notifications`}
      />
    </PageTransition>
  )
}

Notifications.layout = appLayout

function emptyTitle(filters: NotificationFilters): string {
  if (filters.search !== '') return 'Nothing matches that search'
  if (filters.tab === 'unread') return 'No unread notifications'
  if (filters.tab === 'read') return 'No read notifications'
  if (filters.tab === 'approvals') return 'Nothing waiting for approval'

  return 'No notifications'
}

/**
 * One notification as a card: its category's colour down the left edge, a large icon,
 * what happened, and a tag for what it is about. Anywhere on the card opens it —
 * marking it read and following its first link — and any further links are chips of
 * their own.
 */
function NotificationCard({
  notification,
  categoryLabel,
  onOpen,
}: {
  notification: AppNotification
  categoryLabel: string
  onOpen: (notification: AppNotification, href?: string) => void
}) {
  const Icon = NOTIFICATION_CATEGORY_ICON[notification.category] ?? Bell
  const tone = NOTIFICATION_CATEGORY_TONE[notification.category] ?? NOTIFICATION_CATEGORY_TONE['general']!
  const [primary, ...others] = notification.actions

  return (
    <motion.li
      initial={{ opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0 }}
      className={cn(
        'relative flex items-center gap-4 overflow-hidden rounded-panel border bg-white/4 py-3 pr-4 pl-6 backdrop-blur-sm transition-colors hover:bg-white/8',
        notification.unread ? 'border-brand/40' : 'border-hairline',
      )}
    >
      <span className={cn('absolute inset-y-0 left-0 w-1', tone.stripe)} aria-hidden />

      <button
        type="button"
        onClick={() => onOpen(notification, primary?.href)}
        className="absolute inset-0 z-0 cursor-pointer"
        aria-label={`Open ${notification.title}`}
      />

      <span className={cn('relative z-10 grid size-11 shrink-0 place-items-center rounded-full', tone.tile)}>
        <Icon size={20} aria-hidden />
      </span>

      <div className="pointer-events-none relative z-10 min-w-0 flex-1">
        <p className={cn('text-md', notification.unread ? 'font-bold text-white' : 'font-semibold text-white/90')}>
          {notification.title}
        </p>
        <p className="mt-0.5 text-sm text-white/85">{notification.detail}</p>

        <div className="mt-2 flex flex-wrap items-center gap-2">
          <span className="rounded-full border border-hairline-strong bg-white/8 px-3 py-0.5 text-xs text-white/90">
            {categoryLabel}
          </span>
          {others.map((action) => (
            <button
              key={action.label}
              type="button"
              onClick={() => onOpen(notification, action.href)}
              className="pointer-events-auto rounded-full border border-brand/40 bg-brand/10 px-3 py-0.5 text-xs font-medium text-brand transition-colors hover:bg-brand/20"
            >
              {action.label}
            </button>
          ))}
        </div>
      </div>

      <div className="pointer-events-none relative z-10 flex shrink-0 items-center gap-3 text-xs text-white/90">
        <span className="whitespace-nowrap">{formatRelative(notification.timestamp)}</span>
        {notification.unread && <span className="size-2.5 rounded-full bg-status-blue" aria-label="Unread" />}
        <ChevronRight size={18} aria-hidden />
      </div>
    </motion.li>
  )
}
