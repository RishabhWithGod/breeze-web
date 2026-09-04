import { Head } from '@inertiajs/react'
import {
  Bot,
  Briefcase,
  CalendarDays,
  CirclePlus,
  DollarSign,
  Hourglass,
  ReceiptText,
  Timer,
  Wallet,
} from 'lucide-react'
import {
  Badge,
  ButtonLink,
  EmptyState,
  IconBubble,
  Table,
} from '@/components/common'
import {
  DashboardPanel,
  IconListRow,
  StatMedallionCard,
} from '@/components/dashboard'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type {
  BillingSnapshot,
  DashboardSummary,
  DraftEstimateRow,
  FeedItem,
  ReviewAttentionRow,
  TableColumn,
  Tone,
} from '@/types'
import { formatCurrency, formatDate } from '@/utils'

export interface HomeProps {
  summary: readonly DashboardSummary[]
  notificationFeed: readonly FeedItem[]
  schedule: readonly FeedItem[]
  billing: BillingSnapshot
  draftEstimates: readonly DraftEstimateRow[]
  reviewsNeedingAttention: readonly ReviewAttentionRow[]
}

const REVIEW_ATTENTION_TONE: Record<ReviewAttentionRow['reviewStatus'], Tone> = {
  pending: 'warning',
  'in-review': 'info',
}

const REVIEW_ATTENTION_LABEL: Record<ReviewAttentionRow['reviewStatus'], string> = {
  pending: 'Not Started',
  'in-review': 'In Review',
}

const QUICK_ACTIONS = [
  { label: 'New Takeoff', icon: Bot, href: ROUTES.upload },
  { label: 'Create Estimate', icon: ReceiptText, href: ROUTES.estimateCreate },
  { label: 'Create Job', icon: Briefcase, href: ROUTES.jobCreate },
  { label: 'View Schedule', icon: CalendarDays, href: ROUTES.schedulingCalendar },
] as const

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
  billing,
  draftEstimates,
  reviewsNeedingAttention,
}: HomeProps) {
  const reviewColumns: readonly TableColumn<ReviewAttentionRow>[] = [
    {
      key: 'project',
      header: 'Project',
      render: (row) => (
        <div className="min-w-0">
          <p className="truncate font-semibold text-white">{row.projectName}</p>
          {row.drawingName && (
            <p className="truncate text-sm text-white/70">{row.drawingName}</p>
          )}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <Badge tone={REVIEW_ATTENTION_TONE[row.reviewStatus]}>
          {REVIEW_ATTENTION_LABEL[row.reviewStatus]}
        </Badge>
      ),
    },
    {
      key: 'received',
      header: 'Received',
      render: (row) => (row.receivedAt ? formatDate(row.receivedAt) : '—'),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => (
        <ButtonLink href={routeTo.review(row.id)} size="sm" variant="secondary">
          Continue Review
        </ButtonLink>
      ),
    },
  ]

  const estimateColumns: readonly TableColumn<DraftEstimateRow>[] = [
    {
      key: 'client',
      header: 'Client',
      render: (row) => (
        <div className="min-w-0">
          <p className="truncate font-semibold text-white">{row.client}</p>
          <p className="truncate text-sm text-white/70">{row.project}</p>
        </div>
      ),
    },
    { key: 'number', header: 'Estimate #', render: (row) => row.number },
    {
      key: 'amount',
      header: 'Amount',
      render: (row) => formatCurrency(row.amount, 2),
    },
    {
      key: 'issued',
      header: 'Issued',
      render: (row) => (row.issuedOn ? formatDate(row.issuedOn) : '—'),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => (
        <ButtonLink href={routeTo.estimateEdit(row.id)} size="sm" variant="secondary">
          Finish Estimate
        </ButtonLink>
      ),
    },
  ]

  const billingRows: readonly {
    label: string
    value: string
    icon: typeof Wallet
    tone: Tone
  }[] = [
    { label: 'Total Outstanding', value: formatCurrency(billing.totalOutstanding, 2), icon: Wallet, tone: 'brand' },
    { label: 'Overdue', value: formatCurrency(billing.overdue, 2), icon: Timer, tone: 'danger' },
    { label: 'Paid This Month', value: formatCurrency(billing.paidThisMonth, 2), icon: DollarSign, tone: 'success' },
    {
      label: 'Average Days to Pay',
      value: billing.averageDaysToPay !== null ? `${billing.averageDaysToPay} days` : '—',
      icon: Hourglass,
      tone: 'info',
    },
  ]

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

      {/* ========================================== Main dashboard grid ====== */}
      <div className="mt-6 grid gap-6 xl:grid-cols-3">
        {/* --------------------------------------------- Work queues ------- */}
        <div className="flex flex-col gap-6 xl:col-span-2">
          <DashboardPanel title="Upcoming Schedule" bodyClassName="max-h-96 overflow-y-auto">
            <ul className="space-y-4">
              {schedule.map((row, index) => (
                <IconListRow key={row.id} row={row} index={index} />
              ))}
            </ul>
          </DashboardPanel>

          <DashboardPanel
            title="Reviews Needing Attention"
            subtitle="AI takeoffs that haven't been signed off yet"
            link={{ label: 'View all takeoffs', href: ROUTES.aiTakeoff }}
          >
            <Table
              columns={reviewColumns}
              rows={reviewsNeedingAttention}
              getRowId={(row) => row.id}
              emptyState={
                <EmptyState
                  icon={Bot}
                  title="Nothing needs review"
                  description="Every AI takeoff has been signed off."
                  size="sm"
                />
              }
            />
          </DashboardPanel>

          <DashboardPanel
            title="Draft Estimates"
            subtitle="Waiting to be finished and sent"
            link={{ label: 'View all estimates', href: ROUTES.estimates }}
          >
            <Table
              columns={estimateColumns}
              rows={draftEstimates}
              getRowId={(row) => row.id}
              emptyState={
                <EmptyState
                  icon={ReceiptText}
                  title="No draft estimates"
                  description="Every estimate has been sent or approved."
                  size="sm"
                />
              }
            />
          </DashboardPanel>
        </div>

        {/* --------------------------------------------- Side column -------- */}
        <div className="flex flex-col gap-6">
          <DashboardPanel title="Billing Snapshot" link={{ label: 'View full report', href: ROUTES.billing }}>
            <ul className="space-y-4">
              {billingRows.map((row) => (
                <li key={row.label} className="flex items-center justify-between gap-3">
                  <span className="flex items-center gap-3 text-md text-white/85">
                    <IconBubble icon={row.icon} tone={row.tone} size="sm" />
                    {row.label}
                  </span>
                  <span className="text-lg font-semibold text-white">{row.value}</span>
                </li>
              ))}
            </ul>
          </DashboardPanel>

          <DashboardPanel title="Quick Actions">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-1">
              {QUICK_ACTIONS.map((action) => (
                <ButtonLink
                  key={action.label}
                  href={action.href}
                  variant="secondary"
                  leftIcon={action.icon}
                  fullWidth
                >
                  {action.label}
                </ButtonLink>
              ))}
            </div>
          </DashboardPanel>

          <DashboardPanel
            title="Notifications"
            link={{ label: 'View all notifications', href: ROUTES.notifications }}
            bodyClassName="max-h-96 overflow-y-auto"
          >
            <ul className="space-y-4">
              {notificationFeed.map((row, index) => (
                <IconListRow key={row.id} row={row} index={index} />
              ))}
            </ul>
          </DashboardPanel>
        </div>
      </div>
    </PageTransition>
  )
}

Home.layout = appLayout
