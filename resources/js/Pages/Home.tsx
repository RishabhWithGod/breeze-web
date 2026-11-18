import { Head, usePage } from '@inertiajs/react'
import {
  ArrowRight,
  CalendarDays,
  DollarSign,
  Hourglass,
  Timer,
  Wallet,
  ScanLine,
  Calculator,
  HardHat,
} from 'lucide-react'
import { Badge, ButtonLink, EmptyState, IconBubble, Table } from '@/components/common'
import { DashboardPanel, IconListRow, KpiTile, StatMedallionCard } from '@/components/dashboard'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { usePermissions } from '@/hooks'
import type {
  BillingSnapshot,
  DashboardSummary,
  DraftEstimateRow,
  FeedItem,
  ReviewAttentionRow,
  SharedPageProps,
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
  { label: 'New Takeoff', icon: ScanLine, href: ROUTES.upload, permission: 'takeoff.create' },
  { label: 'Create Estimate', icon: Calculator, href: ROUTES.estimateCreate, permission: 'estimates.create' },
  { label: 'Create Job', icon: HardHat, href: ROUTES.jobCreate, permission: 'jobs.create' },
  { label: 'View Schedule', icon: CalendarDays, href: ROUTES.schedulingCalendar, permission: 'schedule.view' },
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
  const { can: permitted } = usePermissions()
  const { auth, company } = usePage<SharedPageProps>().props
  const firstName = auth.user?.name.split(' ')[0] ?? ''
  const hour = new Date().getHours()
  const greeting = hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening'

  // The first action the person may take is the primary one; the rest sit beside it.
  const heroActions = QUICK_ACTIONS.filter((action) => permitted(action.permission))

  const waiting = reviewsNeedingAttention.length + draftEstimates.length
  const attentionLine =
    waiting > 0
      ? `${waiting} ${waiting === 1 ? 'thing needs' : 'things need'} you today` +
        (reviewsNeedingAttention.length > 0
          ? `, including ${reviewsNeedingAttention.length} AI takeoff${reviewsNeedingAttention.length === 1 ? '' : 's'} to review.`
          : '.')
      : "You're all caught up — nothing is waiting on you."
  const reviewColumns: readonly TableColumn<ReviewAttentionRow>[] = [
    {
      key: 'project',
      header: 'Project',
      render: (row) => (
        <div className="min-w-0">
          <p className="truncate font-semibold text-white">{row.projectName}</p>
          {row.drawingName && <p className="truncate text-sm text-white/70">{row.drawingName}</p>}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <Badge tone={REVIEW_ATTENTION_TONE[row.reviewStatus]}>{REVIEW_ATTENTION_LABEL[row.reviewStatus]}</Badge>
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
        <ButtonLink href={routeTo.review(row.id)} size="sm" variant="secondary" rightIcon={ArrowRight}>
          Continue Review
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

      {/* ================================================ Hero =========== */}
      <section
        aria-label="Workspace summary"
        className="blueprint mb-5 grid gap-7 p-6 sm:p-8 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]"
      >
        <span className="bp-corner tl" aria-hidden />
        <span className="bp-corner br" aria-hidden />

        <div className="min-w-0">
          <span className="bp-eyebrow">
            {formatDate(new Date().toISOString(), 'EEEE, MMMM d')}
            {company ? ` · ${company.name}` : ''}
          </span>
          <h1 className="mt-3 mb-2 text-[clamp(1.75rem,3vw,2.5rem)] leading-tight font-semibold">
            Good {greeting}
            {firstName ? `, ${firstName}` : ''}.
          </h1>
          <p className="max-w-lg text-md text-[#a7b7d9]">{attentionLine}</p>

          <div className="mt-6 flex flex-wrap gap-2.5">
            {heroActions.map((action, index) => (
              <ButtonLink
                key={action.label}
                href={action.href}
                size="md"
                leftIcon={action.icon}
                variant={index === 0 ? 'primary' : 'ghost'}
                {...(index === 0
                  ? {}
                  : { className: 'border-[rgb(126_170_255/0.28)] bg-white/6 text-white hover:bg-white/12' })}
              >
                {action.label}
              </ButtonLink>
            ))}
          </div>
        </div>

        <div className="grid grid-cols-2 content-start gap-3">
          {summary.map((stat, index) => (
            <StatMedallionCard key={stat.id} stat={stat} index={index} />
          ))}
          <KpiTile
            index={summary.length}
            icon={Wallet}
            label="Outstanding"
            value={formatCurrency(billing.totalOutstanding, 0)}
            detail={billing.overdue > 0 ? `${formatCurrency(billing.overdue, 0)} overdue` : 'Nothing overdue'}
            alert={billing.overdue > 0}
            href={ROUTES.billing}
          />
        </div>
      </section>

      {/* ============================================ Row 1: work queues ===== */}
      <div className="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <DashboardPanel
          title="Reviews Needing Attention"
          subtitle="AI takeoffs that haven't been signed off yet"
          link={{ label: 'All takeoffs', href: ROUTES.aiTakeoff }}
          flush
        >
          <Table
            variant="lined"
            columns={reviewColumns}
            rows={reviewsNeedingAttention}
            getRowId={(row) => row.id}
            emptyState={
              <EmptyState
                icon={ScanLine}
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
          link={{ label: 'All estimates', href: ROUTES.estimates }}
        >
          {draftEstimates.length === 0 ? (
            <EmptyState
              icon={Calculator}
              title="No draft estimates"
              description="Every estimate has been sent or approved."
              size="sm"
            />
          ) : (
            <ul>
              {draftEstimates.map((row) => (
                <li
                  key={row.id}
                  className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-hairline py-3 first:pt-0 last:border-b-0 last:pb-0"
                >
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-white">{row.client}</p>
                    <p className="truncate text-sm text-white/65">{row.project}</p>
                    <p className="mt-0.5 text-xs text-white/55">
                      {row.number} · {row.issuedOn ? formatDate(row.issuedOn) : '—'}
                    </p>
                  </div>
                  <span className="font-semibold text-white tabular-nums">{formatCurrency(row.amount, 2)}</span>
                  <ButtonLink href={routeTo.estimateEdit(row.id)} size="sm" variant="secondary">
                    Finish Estimate
                  </ButtonLink>
                </li>
              ))}
            </ul>
          )}
        </DashboardPanel>
      </div>

      {/* ============================================ Row 2: three cards ===== */}
      <div className="mt-5 grid gap-5 lg:grid-cols-2 xl:grid-cols-3">
        <DashboardPanel title="Upcoming Schedule" subtitle={`${schedule.length} coming up`} link={{ label: 'Schedule', href: ROUTES.schedulingCalendar }} bodyClassName="max-h-96 overflow-y-auto">
          {schedule.length === 0 ? (
            <EmptyState
              icon={CalendarDays}
              title="Nothing scheduled"
              description="Jobs and tasks with an upcoming date will show here."
              size="sm"
            />
          ) : (
            <ul>
              {schedule.map((row, index) => (
                <IconListRow key={row.id} row={row} index={index} />
              ))}
            </ul>
          )}
        </DashboardPanel>

        <DashboardPanel title="Billing Snapshot" subtitle="Where your invoices stand" link={{ label: 'Full report', href: ROUTES.billing }}>
          <ul>
            {billingRows.map((row) => (
              <li
                key={row.label}
                className="flex items-center justify-between gap-3 border-b border-hairline py-3 first:pt-0 last:border-b-0 last:pb-0"
              >
                <span className="flex items-center gap-3 text-md text-white/85">
                  <IconBubble icon={row.icon} tone={row.tone} size="sm" />
                  {row.label}
                </span>
                <span className="font-display text-lg font-semibold text-white tabular-nums">{row.value}</span>
              </li>
            ))}
          </ul>
        </DashboardPanel>

        <DashboardPanel
          title="Notifications"
          subtitle="Recent activity"
          link={{ label: 'View all', href: ROUTES.notifications }}
          bodyClassName="max-h-96 overflow-y-auto"
        >
          <ul>
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
