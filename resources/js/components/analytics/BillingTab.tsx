import { Card, EmptyState, SectionHeading } from '@/components/common'
import { ButtonLink } from '@/components/common'
import { ComparisonBarChart } from '@/components/jobCosting'
import { routeTo } from '@/constants'
import type { AnalyticsBillingReport, AnalyticsTrendPoint, JobCostingDashboardProps } from '@/types'
import { formatCurrency } from '@/utils'
import { MonthlyTrendChart } from './MonthlyTrendChart'
import { SortableTable, StatBlock, type SortableColumn } from './shared'

type Invoice = AnalyticsBillingReport['invoices'][number]

export function BillingTab({
  report,
  billing,
  trend,
}: {
  report: AnalyticsBillingReport | null
  billing: JobCostingDashboardProps['billing']
  trend: readonly AnalyticsTrendPoint[] | null
}) {
  if (report === null || billing.billed === null) {
    return (
      <Card accent="neutral" padding="lg">
        <EmptyState title="Billing figures are restricted" description="Only a Client Manager, Admin or Owner can see billing and aging." />
      </Card>
    )
  }

  const collectedPct = billing.billed > 0 ? Math.round(((billing.paid ?? 0) / billing.billed) * 100) : null
  const invoiceColumns: SortableColumn<Invoice>[] = [
    { key: 'number', header: 'Invoice', sortValue: (i) => i.number, render: (i) => <span className="font-semibold text-white">{i.number}</span> },
    {
      key: 'job',
      header: 'Job',
      sortValue: (i) => i.jobName ?? '',
      render: (i) =>
        i.jobId !== null ? (
          <ButtonLink href={routeTo.jobCosting(i.jobId)} variant="ghost" size="sm" className="h-auto p-0">
            {i.jobName ?? 'Job'}
          </ButtonLink>
        ) : (
          '—'
        ),
    },
    { key: 'client', header: 'Client', sortValue: (i) => i.client ?? '', render: (i) => i.client ?? '—' },
    { key: 'due', header: 'Due', sortValue: (i) => i.dueDate ?? '', render: (i) => i.dueDate ?? '—' },
    {
      key: 'late',
      header: 'Days overdue',
      align: 'right',
      sortValue: (i) => i.daysOverdue,
      render: (i) => (i.isOverdue ? <span className="font-medium text-status-danger">{i.daysOverdue}</span> : '—'),
    },
    { key: 'balance', header: 'Balance', align: 'right', sortValue: (i) => i.balance, render: (i) => formatCurrency(i.balance, 2) },
  ]

  return (
    <div className="grid gap-6">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <StatBlock label="Billed" value={formatCurrency(billing.billed, 0)} />
        <StatBlock label="Collected" value={formatCurrency(billing.paid ?? 0, 0)} detail={collectedPct !== null ? `${collectedPct}% of billed` : undefined} />
        <StatBlock label="Outstanding" value={formatCurrency(billing.outstanding ?? 0, 0)} />
        <StatBlock
          label="Overdue"
          value={formatCurrency(report.overdueTotal, 0)}
          detail={`${report.overdueCount} invoice${report.overdueCount === 1 ? '' : 's'}`}
          note={report.overdueCount > 0 ? 'Follow up' : 'Nothing overdue'}
          noteTone={report.overdueCount > 0 ? 'danger' : 'success'}
        />
        <StatBlock label="Unbilled" value={formatCurrency(billing.unbilled ?? 0, 0)} detail="contract not yet invoiced" />
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card accent="warning" padding="lg">
          <SectionHeading as="h3" title="Invoice Aging" />
          <ComparisonBarChart
            rows={[
              { label: 'Current / not due', value: report.aging.current, barClassName: 'bg-status-success' },
              { label: '1–30 days overdue', value: report.aging.d1_30, barClassName: 'bg-status-warning' },
              { label: '31–60 days overdue', value: report.aging.d31_60, barClassName: 'bg-status-warning' },
              { label: '61+ days overdue', value: report.aging.d61_plus, barClassName: 'bg-status-danger' },
            ]}
            formatValue={(v) => formatCurrency(v, 0)}
          />
        </Card>

        <Card accent="brand" padding="lg">
          <SectionHeading as="h3" title="Billed vs Collected by Month" />
          {trend && trend.length > 0 ? <MonthlyTrendChart points={trend} /> : <EmptyState title="No history yet" description="Invoices will chart here by month." />}
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        <Card accent="neutral" padding="lg" className="xl:col-span-2">
          <SectionHeading as="h3" title="Largest Outstanding Invoices" />
          <SortableTable columns={invoiceColumns} rows={report.invoices} getRowId={(i) => i.id} initialSort={{ key: 'balance', dir: 'desc' }} emptyText="No outstanding invoices." />
        </Card>

        <Card accent="info" padding="lg">
          <SectionHeading as="h3" title="Who Owes the Most" />
          {report.byClient.length === 0 ? (
            <EmptyState title="All paid up" description="No client has an outstanding balance." />
          ) : (
            <ul className="flex flex-col gap-3">
              {report.byClient.map((c) => (
                <li key={c.client} className="flex items-center justify-between gap-3 text-sm">
                  <div className="min-w-0">
                    <p className="truncate font-semibold text-white">{c.client}</p>
                    <p className="text-white/70">{c.invoices} invoice{c.invoices === 1 ? '' : 's'}</p>
                  </div>
                  <span className="shrink-0 font-semibold tabular-nums text-white">{formatCurrency(c.balance, 0)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  )
}
