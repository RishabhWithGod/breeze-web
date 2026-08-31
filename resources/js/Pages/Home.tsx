import { Head } from '@inertiajs/react'
import { CirclePlus } from 'lucide-react'
import { ButtonLink } from '@/components/common'
import {
  DashboardPanel,
  IconListRow,
  StatMedallionCard,
} from '@/components/dashboard'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { DashboardSummary, FeedItem } from '@/types'

export interface HomeProps {
  summary: readonly DashboardSummary[]
  /**
   * Rows for the dashboard's Notifications panel. Deliberately not called
   * `notifications` — that is a shared prop belonging to the header bell.
   */
  notificationFeed: readonly FeedItem[]
  schedule: readonly FeedItem[]
}

/**
 * Dashboard — the landing screen after sign-in.
 *
 * Composition only: every surface is a reusable component, and every figure is
 * counted from the database by DashboardController.
 */
export default function Home({
  summary,
  notificationFeed,
  schedule,
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

      {/* ==================================== Schedule + notifications ======= */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <DashboardPanel title="Upcoming Schedule" bodyClassName="max-h-96 overflow-y-auto">
          <ul className="space-y-4">
            {schedule.map((row, index) => (
              <IconListRow key={row.id} row={row} index={index} />
            ))}
          </ul>
        </DashboardPanel>

        <DashboardPanel
          title="Notifications"
          link={{ label: 'View all notifications', href: ROUTES.notifications }}
          index={1}
          bodyClassName="max-h-96 overflow-y-auto"
        >
          <ul className="space-y-4">
            {notificationFeed.map((row, index) => (
              <IconListRow key={row.id} row={row} index={index} />
            ))}
          </ul>
        </DashboardPanel>
      </div>
    </PageTransition>
  )
}

Home.layout = appLayout
