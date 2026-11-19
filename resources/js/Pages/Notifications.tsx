import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Bell, ChevronDown, ChevronRight, Filter, MailOpen, SearchX } from 'lucide-react'
import { Alert, Button, EmptyState, Pagination, SearchBox, Seg } from '@/components/common'
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
        <Seg
          ariaLabel="Notification views"
          options={NOTIFICATION_TABS.map((tab) => {
            const count = tab.value === 'all' ? 0 : tabCounts[tab.value]

            return { key: tab.value, label: count > 0 ? `${tab.label} (${count})` : tab.label }
          })}
          value={filters.tab}
          onChange={(value) => applyFilters({ tab: value as NotificationTab })}
        />

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
              className="cursor-pointer appearance-none h-11 rounded-[10px] border border-hairline-strong bg-surface-glass pr-10 pl-11 text-[14px] font-medium text-white focus:border-brand focus:outline-none"
            >
              <option value="all" className="bg-surface-glass">
                Filter
              </option>
              {categories.map((option) => (
                <option key={option.value} value={option.value} className="bg-surface-glass">
                  {option.label}
                </option>
              ))}
            </select>
            <ChevronDown size={16} aria-hidden className="pointer-events-none absolute right-4 text-white/85" />
          </label>

          <Button variant="secondary" leftIcon={MailOpen} onClick={markAllRead} disabled={tabCounts.unread === 0}>
            Mark All Read
          </Button>
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
        'group glass relative flex items-center gap-4 overflow-hidden rounded-card border px-[18px] py-4 transition-[transform,border-color,box-shadow] duration-150 hover:-translate-y-0.5 hover:border-brand/45 hover:shadow-[0_16px_36px_-22px_rgb(47_91_255/0.55)]',
        notification.unread ? 'border-brand/35' : 'border-hairline',
      )}
    >
      {/* A soft wash of the category's colour behind an unread card, so it reads before it is read. */}
      {notification.unread && (
        <span
          aria-hidden
          className="pointer-events-none absolute -top-14 -right-10 size-44 rounded-full bg-[radial-gradient(circle,var(--glow-a),transparent_70%)]"
        />
      )}

      <button
        type="button"
        onClick={() => onOpen(notification, primary?.href)}
        className="absolute inset-0 z-0 cursor-pointer"
        aria-label={`Open ${notification.title}`}
      />

      <span className="relative z-10 shrink-0">
        <span className={cn('grid size-12 place-items-center rounded-[14px]', tone.tile)}>
          <Icon size={22} aria-hidden />
        </span>
        {notification.unread && (
          <span
            className="absolute -top-1 -right-1 size-3 rounded-full bg-brand ring-2 ring-[var(--surface)]"
            aria-label="Unread"
          />
        )}
      </span>

      <div className="pointer-events-none relative z-10 min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <p
            className={cn(
              'font-display text-[15px] leading-snug tracking-[-0.01em]',
              notification.unread ? 'font-[650] text-white' : 'font-[560] text-[var(--text-2)]',
            )}
          >
            {notification.title}
          </p>
          <span className="rounded-full bg-[var(--surface-3)] px-2.5 py-px text-[11px] font-semibold tracking-[0.03em] text-[var(--text-2)] uppercase">
            {categoryLabel}
          </span>
        </div>
        <p className="mt-1 text-[13.5px] leading-snug text-[var(--text-2)]">{notification.detail}</p>

        {(primary || others.length > 0) && (
          <div className="mt-2.5 flex flex-wrap items-center gap-2">
            {primary && (
              <span className="inline-flex items-center gap-1 rounded-full bg-[var(--brand-bg)] px-3 py-1 text-xs font-semibold text-brand">
                {primary.label}
                <ChevronRight size={13} aria-hidden />
              </span>
            )}
            {others.map((action) => (
              <button
                key={action.label}
                type="button"
                onClick={() => onOpen(notification, action.href)}
                className="pointer-events-auto rounded-full border border-hairline-strong px-3 py-1 text-xs font-medium text-[var(--text-2)] transition-colors hover:border-brand/45 hover:text-brand"
              >
                {action.label}
              </button>
            ))}
          </div>
        )}
      </div>

      <div className="pointer-events-none relative z-10 flex shrink-0 flex-col items-end gap-2 text-xs text-[var(--text-3)]">
        <span className="whitespace-nowrap">{formatRelative(notification.timestamp)}</span>
        {/* Round arrow button hidden for now — the whole card is the click target.
        <span className="grid size-7 place-items-center rounded-full bg-[var(--surface-3)] text-[var(--text-2)] transition-colors group-hover:bg-brand group-hover:text-brand-ink">
          <ChevronRight size={15} aria-hidden />
        </span>
        */}
      </div>
    </motion.li>
  )
}
