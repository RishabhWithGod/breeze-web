import { Head } from '@inertiajs/react'
import { CirclePlus } from 'lucide-react'
import { ButtonLink } from '@/components/common'
import {
  DashboardPanel,
  IconListRow,
  PerformanceChart,
  StatMedallionCard,
} from '@/components/dashboard'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { DashboardSummary, FeedItem, MonthlyPoint } from '@/types'

export interface HomeProps {
  summary: readonly DashboardSummary[]
  activity: readonly FeedItem[]
  /**
   * Rows for the dashboard's Notifications panel. Deliberately not called
   * `notifications` — that is a shared prop belonging to the header bell.
   */
  notificationFeed: readonly FeedItem[]
  schedule: readonly FeedItem[]
  performance: readonly MonthlyPoint[]
}

/**
 * Dashboard — the landing screen after sign-in.
 *
 * Composition only: every surface is a reusable component, and every figure is
 * counted from the database by DashboardController.
 */
export default function Home({
  summary,
  activity,
  notificationFeed,
  schedule,
  performance,
}: HomeProps) {
  return (
    <PageTransition>
      <Head title="Dashboard" />

      {/* ================================================ Page title ========= */}
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-3xl font-bold text-white sm:text-4xl">Dashboard</h1>

        <ButtonLink href={ROUTES.upload} variant="dark" leftIcon={CirclePlus}>
          Create New Takeoff
        </ButtonLink>
      </div>

      {/* ============================================= Summary cards ========= */}
      <section aria-label="Workspace summary">
        <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
          {summary.map((stat, index) => (
            <StatMedallionCard key={stat.id} stat={stat} index={index} />
          ))}
        </div>
      </section>

      {/* =================================== Activity + notifications ======== */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <DashboardPanel title="Recent Activity">
          <ul className="space-y-4">
            {activity.map((row, index) => (
              <IconListRow key={row.id} row={row} index={index} />
            ))}
          </ul>
        </DashboardPanel>

        <DashboardPanel
          title="Notifications"
          link={{ label: 'View all estimates', href: ROUTES.results }}
          index={1}
        >
          <ul className="space-y-4">
            {notificationFeed.map((row, index) => (
              <IconListRow key={row.id} row={row} index={index} />
            ))}
          </ul>
        </DashboardPanel>
      </div>

      {/* ====================================== Performance + schedule ======= */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <DashboardPanel title="Monthly Performance">
          <PerformanceChart data={performance} seriesLabel="Monthly Performance" />
        </DashboardPanel>

        <DashboardPanel title="Upcoming Schedule" index={1}>
          <ul className="space-y-4">
            {schedule.map((row, index) => (
              <IconListRow key={row.id} row={row} index={index} />
            ))}
          </ul>
        </DashboardPanel>
      </div>
    </PageTransition>
  )
}

Home.layout = appLayout
