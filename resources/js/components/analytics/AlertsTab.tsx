import { useState } from 'react'
import { Card, EmptyState, FilterTabs, SectionHeading } from '@/components/common'
import type { AnalyticsAlert } from '@/types'
import { AlertList } from './AlertList'

const TYPE_FILTERS = [
  { label: 'All', value: 'all' },
  { label: 'Over budget', value: 'overrun' },
  { label: 'Hours', value: 'hours_warning' },
  { label: 'Margin', value: 'low_margin' },
  { label: 'Billing', value: 'billing' },
  { label: 'Time', value: 'missing_checkout' },
] as const

type TypeFilter = (typeof TYPE_FILTERS)[number]['value']

const matches = (alert: AnalyticsAlert, filter: TypeFilter) =>
  filter === 'all' ||
  alert.type === filter ||
  (filter === 'billing' && (alert.type === 'unbilled' || alert.type === 'overdue_invoice'))

export function AlertsTab({ alerts }: { alerts: readonly AnalyticsAlert[] }) {
  const [filter, setFilter] = useState<TypeFilter>('all')
  const visible = alerts.filter((a) => matches(a, filter))
  const counts = Object.fromEntries(TYPE_FILTERS.map((f) => [f.value, alerts.filter((a) => matches(a, f.value)).length])) as Record<TypeFilter, number>

  return (
    <Card accent="warning" padding="lg">
      <SectionHeading as="h3" title="Needs Attention" />
      <FilterTabs options={TYPE_FILTERS} value={filter} onChange={setFilter} counts={counts} className="mb-5" />
      {visible.length === 0 ? (
        <EmptyState title="Nothing needs attention" description="No jobs, invoices or timers match this filter right now." />
      ) : (
        <AlertList alerts={visible} />
      )}
    </Card>
  )
}
