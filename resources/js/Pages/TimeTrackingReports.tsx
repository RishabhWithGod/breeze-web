import { useState } from 'react'
import { Head, router } from '@inertiajs/react'
import { ArrowLeft, Download } from 'lucide-react'
import {
  Button,
  ButtonLink,
  Card,
  CardHeader,
  ProgressBar,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { TimeTrackingReportsData } from '@/types'
import { formatCurrency, formatHours } from '@/utils'

export type TimeTrackingReportsProps = TimeTrackingReportsData

/**
 * Reports — time by job, by employee, by task, billable split, overtime,
 * labor cost, and estimated vs actual, for a date range. Every figure comes
 * from `TimeTrackingReportController`, computed the same way the dashboard
 * and Job Detail compute theirs — a report can never disagree with the
 * screen it summarises.
 */
export default function TimeTrackingReports({
  range,
  byJob,
  byEmployee,
  byTask,
  billableSplit,
  overtimeTotal,
  laborCostTotal,
  estimatedVsActual,
  canViewCosts,
}: TimeTrackingReportsProps) {
  const [from, setFrom] = useState(range.from)
  const [to, setTo] = useState(range.to)

  const applyRange = () => {
    router.get(ROUTES.timeTrackingReports, { from, to }, { preserveState: true })
  }

  const totalBillable = billableSplit.billable + billableSplit.nonBillable
  const billablePct = totalBillable > 0 ? (billableSplit.billable / totalBillable) * 100 : 0

  return (
    <PageTransition>
      <Head title="Time Tracking Reports" />

      <PageHeader
        title="Reports"
        subtitle="Time by job, by employee, by task — for any date range."
        breadcrumbs={[{ label: 'Time Tracking', href: ROUTES.timeTracking }, { label: 'Reports' }]}
        actions={
          <>
            <Button
              variant="secondary"
              leftIcon={Download}
              onClick={() => window.open(`${routeTo.timeTrackingReportsExport('csv')}?from=${from}&to=${to}`, '_blank')}
            >
              Export CSV
            </Button>
            <Button
              variant="secondary"
              leftIcon={Download}
              onClick={() => window.open(`${routeTo.timeTrackingReportsExport('xlsx')}?from=${from}&to=${to}`, '_blank')}
            >
              Export Excel
            </Button>
                      <ButtonLink href={ROUTES.timeTracking} variant="secondary" leftIcon={ArrowLeft}>
              Back
            </ButtonLink>
          </>
        }
      />

      <Card padding="lg">
        <div className="flex flex-wrap items-end gap-4">
          <TextInput
            id="report-from"
            type="date"
            label="From"
            value={from}
            onChange={(event) => setFrom(event.target.value)}
          />
          <TextInput
            id="report-to"
            type="date"
            label="To"
            value={to}
            onChange={(event) => setTo(event.target.value)}
          />
          <Button onClick={applyRange}>Apply</Button>
        </div>
      </Card>

      <div className="mt-6 grid gap-6 xl:grid-cols-3">
        <Card padding="lg">
          <CardHeader title="Billable vs Non-billable" />
          <ProgressBar value={billablePct} showValue tone="success" />
          <dl className="mt-4 space-y-2 text-md">
            <div className="flex justify-between text-white">
              <dt>Billable</dt>
              <dd className="tabular-nums">{formatHours(billableSplit.billable)}</dd>
            </div>
            <div className="flex justify-between text-white/80">
              <dt>Non-billable</dt>
              <dd className="tabular-nums">{formatHours(billableSplit.nonBillable)}</dd>
            </div>
          </dl>
        </Card>

        <Card padding="lg">
          <CardHeader title="Overtime" />
          <p className="text-3xl font-bold text-white">{formatHours(overtimeTotal)}</p>
          <p className="mt-1 text-sm text-white/75">Total overtime hours in range</p>
        </Card>

        {canViewCosts && (
          <Card padding="lg">
            <CardHeader title="Labor Cost" />
            <p className="text-3xl font-bold text-white">
              {laborCostTotal !== null ? formatCurrency(laborCostTotal, 0) : '—'}
            </p>
            <p className="mt-1 text-sm text-white/75">Total approved labor cost in range</p>
          </Card>
        )}
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-3">
        <ReportTable title="Time by Job" rows={byJob} showCost={canViewCosts} />
        <ReportTable title="Time by Employee" rows={byEmployee} showOvertime showCost={canViewCosts} />
        <ReportTable title="Time by Task" rows={byTask} />
      </div>

      <Card padding="lg" className="mt-6">
        <CardHeader title="Estimated vs Actual" subtitle="Task-plan hours against approved time, per job" />
        {estimatedVsActual.length === 0 ? (
          <p className="text-md text-white/75">No jobs with logged time in this range.</p>
        ) : (
          <ul className="divide-y divide-hairline">
            {estimatedVsActual.map((row) => (
              <li key={row.jobId} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <span className="font-medium text-white">{row.job}</span>
                <span className="text-sm text-white/85">
                  Estimated {formatHours(row.estimatedHours)} · Actual {formatHours(row.actualHours)} ·{' '}
                  <span
                    className={
                      row.remainingHours > 0 ? 'text-status-success' : 'text-status-warning'
                    }
                  >
                    {row.remainingHours > 0
                      ? `${formatHours(row.remainingHours)} remaining`
                      : 'At or over estimate'}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </PageTransition>
  )
}

TimeTrackingReports.layout = appLayout

function ReportTable({
  title,
  rows,
  showOvertime = false,
  showCost = false,
}: {
  title: string
  rows: readonly { id: number; label: string; hours: number; overtime?: number; cost?: number }[]
  showOvertime?: boolean
  showCost?: boolean
}) {
  return (
    <Card padding="lg">
      <CardHeader title={title} />
      {rows.length === 0 ? (
        <p className="text-md text-white/75">No data in this range.</p>
      ) : (
        <ul className="divide-y divide-hairline">
          {rows.map((row) => (
            <li key={row.id} className="flex items-center justify-between gap-3 py-2.5 text-md">
              <span className="min-w-0 truncate text-white">{row.label}</span>
              <span className="shrink-0 text-right text-white/90">
                <span className="tabular-nums">{formatHours(row.hours)}</span>
                {showOvertime && row.overtime ? (
                  <span className="ml-1 text-xs text-status-warning">
                    (+{formatHours(row.overtime)} OT)
                  </span>
                ) : null}
                {showCost && row.cost ? (
                  <span className="ml-2 text-sm text-white/70">{formatCurrency(row.cost, 0)}</span>
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
