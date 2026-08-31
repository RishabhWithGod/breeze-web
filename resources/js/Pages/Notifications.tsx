import { useCallback, useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ArrowLeft, Bell, CheckCheck, SearchX } from 'lucide-react'
import { Alert, Button, ButtonLink, EmptyState, FilterTabs, IconBubble, Pagination, SelectField } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { NOTIFICATION_CATEGORY_ICON, NOTIFICATION_TABS, ROUTES, routeTo } from '@/constants'
import type {
  AppNotification,
  NotificationCategoryOption,
  NotificationFilters,
  NotificationTab,
  NotificationTabCounts,
  Paginated,
  SharedPageProps,
} from '@/types'
import { formatModified } from '@/utils'

export interface NotificationsProps {
  notifications: Paginated<AppNotification>
  filters: NotificationFilters
  tabCounts: NotificationTabCounts
  categories: readonly NotificationCategoryOption[]
}

/**
 * Notification Center — every notification the signed-in user has ever
 * received, real and database-backed. Reading one here (or clicking an
 * action) marks it read in the same request that navigates, so the header
 * bell's unread count is always in sync, never a client-side guess.
 */
export default function Notifications({ notifications, filters, tabCounts, categories }: NotificationsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)

  const notice = flash.success ?? null
  const displayNotice = notice === dismissed ? null : notice

  const rows = notifications.data
  const { meta } = notifications

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

  const changeTab = (tab: NotificationTab) => applyFilters({ tab })

  const markAllRead = () => {
    router.post(ROUTES.notificationsReadAll, {}, { preserveScroll: true })
  }

  return (
    <PageTransition>
      <Head title="Notification Center" />

      <PageHeader
        title="Notification Center"
        subtitle="Manage all your system notifications in one place"
        actions={
          <ButtonLink href={ROUTES.home} variant="secondary" leftIcon={ArrowLeft}>
            Back to Dashboard
          </ButtonLink>
        }
      />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert key={displayNotice} tone="success" className="mb-6" onDismiss={() => setDismissed(displayNotice)}>
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <FilterTabs options={NOTIFICATION_TABS} value={filters.tab} onChange={changeTab} counts={tabCounts} solid />

        {/*
          `SelectField` wraps itself in an unconditional `w-full` container
          regardless of any width class passed in, so it has to be given its
          width from an outer wrapper here — otherwise it claims the full row
          width and pushes the button onto its own line.
        */}
        <div className="flex items-center gap-3">
          <div className="min-w-0 flex-1 sm:w-44 sm:flex-none">
            <SelectField
              id="notification-category-filter"
              aria-label="Filter by Type"
              options={[{ label: 'Filter by Type', value: 'all' }, ...categories]}
              value={filters.category}
              onChange={(event) => applyFilters({ category: event.target.value })}
            />
          </div>
          <Button
            variant="white"
            leftIcon={CheckCheck}
            onClick={markAllRead}
            disabled={tabCounts.unread === 0}
            className="shrink-0 whitespace-nowrap"
          >
            Mark All as Read
          </Button>
        </div>
      </div>

      <div className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <div className="p-5 sm:p-6">
          {rows.length === 0 ? (
            <EmptyState
              icon={SearchX}
              title={emptyTitle(filters.tab)}
              description="Notifications from Jobs, Estimates, Scheduling, AI Takeoff, Time Tracking, Documents, Billing and Job Costing all appear here."
            />
          ) : (
            <ul className="divide-y divide-hairline">
              <AnimatePresence initial={false}>
                {rows.map((notification) => (
                  <NotificationRow key={notification.id} notification={notification} />
                ))}
              </AnimatePresence>
            </ul>
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => applyFilters({ page })}
            summary={meta.total === 0 ? 'No notifications to display' : `Showing ${rows.length} of ${meta.total} notifications`}
          />
        </div>
      </div>
    </PageTransition>
  )
}

function emptyTitle(tab: NotificationTab): string {
  if (tab === 'unread') return 'No unread notifications'
  if (tab === 'read') return 'No read notifications'
  return 'No notifications'
}

function NotificationRow({ notification }: { notification: AppNotification }) {
  const Icon = NOTIFICATION_CATEGORY_ICON[notification.category] ?? Bell

  return (
    <motion.li
      initial={{ opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0 }}
      className={`flex gap-4 px-2 py-5 first:pt-1 ${notification.unread ? 'bg-brand/4' : ''}`}
    >
      <IconBubble icon={Icon} tone={notification.unread ? 'brand' : 'neutral'} size="sm" className="mt-0.5" />

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <p className={`text-md ${notification.unread ? 'font-semibold text-white' : 'font-medium text-white/85'}`}>
            {notification.title}
            {notification.unread && (
              <span className="ml-2 inline-block size-1.5 rounded-full bg-brand align-middle" aria-label="Unread" />
            )}
          </p>
          <span className="shrink-0 text-xs text-white/60">{formatModified(notification.timestamp)}</span>
        </div>

        <p className="mt-1 text-sm text-white/80">{notification.detail}</p>

        {notification.actions.length > 0 && (
          <div className="mt-2 flex flex-wrap items-center gap-4">
            {notification.actions.map((action, index) => (
              <Link
                key={action.label}
                href={routeTo.notificationRead(notification.id)}
                method="post"
                data={{ redirect: action.href }}
                className={index === 0 ? 'text-sm font-semibold text-brand hover:underline' : 'text-sm font-semibold text-white/85 hover:text-white hover:underline'}
              >
                {action.label}
              </Link>
            ))}
          </div>
        )}
      </div>
    </motion.li>
  )
}

Notifications.layout = appLayout
