import { Card, EmptyState, ProgressBar, SectionHeading } from '@/components/common'
import { ButtonLink } from '@/components/common'
import { routeTo } from '@/constants'
import type { AnalyticsLabor, AnalyticsLaborPerson, AnalyticsLaborRole, JobCostRow } from '@/types'
import { formatCurrency, formatHours } from '@/utils'
import { SortableTable, StatBlock, type SortableColumn } from './shared'

export function LaborTab({ labor, rows, canViewCosts }: { labor: AnalyticsLabor; rows: readonly JobCostRow[]; canViewCosts: boolean }) {
  const personColumns: SortableColumn<AnalyticsLaborPerson>[] = [
    { key: 'name', header: 'Person', sortValue: (p) => p.name.toLowerCase(), render: (p) => <span className="font-semibold text-white">{p.name}</span> },
    { key: 'role', header: 'Role', sortValue: (p) => p.role, render: (p) => p.role },
    { key: 'hours', header: 'Hours', align: 'right', sortValue: (p) => p.hours, render: (p) => formatHours(p.hours) },
    { key: 'ot', header: 'Overtime', align: 'right', sortValue: (p) => p.overtimeHours, render: (p) => `${formatHours(p.overtimeHours)} (${p.overtimePct}%)` },
    { key: 'billable', header: 'Billable', align: 'right', sortValue: (p) => p.billablePct, render: (p) => `${p.billablePct}%` },
    { key: 'jobs', header: 'Jobs', align: 'right', sortValue: (p) => p.jobs, render: (p) => p.jobs },
    ...(canViewCosts
      ? ([{ key: 'cost', header: 'Labor cost', align: 'right', sortValue: (p) => p.cost, render: (p) => (p.cost === null ? '—' : formatCurrency(p.cost, 0)) }] satisfies SortableColumn<AnalyticsLaborPerson>[])
      : []),
  ]

  const roleColumns: SortableColumn<AnalyticsLaborRole>[] = [
    { key: 'role', header: 'Role', sortValue: (r) => r.role, render: (r) => <span className="font-semibold text-white">{r.role}</span> },
    { key: 'people', header: 'People', align: 'right', sortValue: (r) => r.people, render: (r) => r.people },
    { key: 'hours', header: 'Hours', align: 'right', sortValue: (r) => r.hours, render: (r) => formatHours(r.hours) },
    { key: 'ot', header: 'OT %', align: 'right', sortValue: (r) => r.overtimePct, render: (r) => `${r.overtimePct}%` },
    ...(canViewCosts
      ? ([{ key: 'cost', header: 'Labor cost', align: 'right', sortValue: (r) => r.cost, render: (r) => (r.cost === null ? '—' : formatCurrency(r.cost, 0)) }] satisfies SortableColumn<AnalyticsLaborRole>[])
      : []),
  ]

  const taskRows = rows.filter((r) => r.estimatedLaborHours > 0)

  return (
    <div className="grid gap-6">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatBlock label="Total Hours" value={formatHours(labor.totals.hours)} detail="from logged time" />
        <StatBlock
          label="Overtime"
          value={formatHours(labor.totals.overtimeHours)}
          detail={`${labor.totals.overtimePct}% of hours`}
          note={labor.totals.overtimePct > 15 ? 'High overtime' : undefined}
          noteTone="danger"
        />
        <StatBlock label="Billable Hours" value={formatHours(labor.totals.billableHours)} detail={`${labor.totals.billablePct}% billable`} />
        <StatBlock label="People" value={String(labor.people.length)} detail={`${labor.roles.length} role${labor.roles.length === 1 ? '' : 's'}`} />
      </div>

      <Card accent="brand" padding="lg">
        <SectionHeading as="h3" title="Hours by Person" />
        {labor.people.length === 0 ? (
          <EmptyState title="No time logged" description="No time entries match the current filters." />
        ) : (
          <SortableTable columns={personColumns} rows={labor.people} getRowId={(p) => p.name + p.role} initialSort={{ key: 'hours', dir: 'desc' }} emptyText="No time logged." />
        )}
      </Card>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card accent="info" padding="lg">
          <SectionHeading as="h3" title="By Role" />
          <SortableTable columns={roleColumns} rows={labor.roles} getRowId={(r) => r.role} initialSort={{ key: 'hours', dir: 'desc' }} emptyText="No time logged." />
        </Card>

        <Card accent="warning" padding="lg">
          <SectionHeading as="h3" title="Hours vs Estimate by Job" />
          {taskRows.length === 0 ? (
            <EmptyState title="No hour estimates" description="Jobs with estimated task hours will show here." />
          ) : (
            <ul className="flex flex-col gap-4">
              {taskRows.map((r) => {
                const used = (r.actualLaborHours / r.estimatedLaborHours) * 100
                return (
                  <li key={r.jobId}>
                    <div className="flex items-center justify-between gap-3 text-sm">
                      <ButtonLink href={routeTo.jobCosting(r.jobId)} variant="ghost" size="sm" className="h-auto min-w-0 truncate p-0 font-semibold">
                        {r.jobName}
                      </ButtonLink>
                      <span className="shrink-0 text-white/85">
                        {formatHours(r.actualLaborHours)} / {formatHours(r.estimatedLaborHours)}
                      </span>
                    </div>
                    <ProgressBar value={Math.min(100, used)} tone={used > 100 ? 'danger' : used >= 90 ? 'warning' : 'success'} size="sm" className="mt-1.5" />
                  </li>
                )
              })}
            </ul>
          )}
        </Card>
      </div>
    </div>
  )
}
