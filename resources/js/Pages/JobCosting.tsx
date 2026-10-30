import { useCallback, useEffect, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Download, Settings2, SlidersHorizontal, X } from 'lucide-react'
import { Button, FilterTabs, SelectField, Skeleton, TextInput } from '@/components/common'
import {
  AlertsTab,
  BillingTab,
  LaborTab,
  MaterialsTab,
  OverviewTab,
  ProfitabilityTab,
  SavedViews,
  SettingsModal,
} from '@/components/analytics'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { JOB_COSTING_COST_TYPE_FILTERS, JOB_STATUS_FILTERS, MOTION, ROUTES, routeTo } from '@/constants'
import { LiveChangeBanner } from '@/components/jobScope'
import { useCompanyScopeSync, useDisclosure } from '@/hooks'
import type { AnalyticsTab, JobCostingDashboardProps, JobCostingFilters } from '@/types'
import { JOB_STATUS_LABEL } from '@/utils'

const TABS: readonly { label: string; value: AnalyticsTab }[] = [
  { label: 'Overview', value: 'overview' },
  { label: 'Profitability', value: 'profitability' },
  { label: 'Labor', value: 'labor' },
  { label: 'Materials', value: 'materials' },
  { label: 'Billing', value: 'billing' },
  { label: 'Alerts', value: 'alerts' },
]

const isoDay = (d: Date) => d.toISOString().slice(0, 10)

/** Quick date windows; "All time" clears the range so revenue and cost stay like-for-like. */
const DATE_PRESETS: readonly { label: string; range: () => [string | null, string | null] }[] = [
  { label: 'All time', range: () => [null, null] },
  { label: 'This month', range: () => { const n = new Date(); return [isoDay(new Date(n.getFullYear(), n.getMonth(), 1)), isoDay(n)] } },
  { label: 'Last 30 days', range: () => { const n = new Date(); return [isoDay(new Date(n.getTime() - 30 * 864e5)), isoDay(n)] } },
  { label: 'This quarter', range: () => { const n = new Date(); return [isoDay(new Date(n.getFullYear(), Math.floor(n.getMonth() / 3) * 3, 1)), isoDay(n)] } },
  { label: 'This year', range: () => { const n = new Date(); return [isoDay(new Date(n.getFullYear(), 0, 1)), isoDay(n)] } },
]

interface FilterChip {
  readonly label: string
  readonly clear: Partial<JobCostingFilters>
}

function toQuery(filters: Partial<JobCostingFilters>): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value === '' || value === 'all' || value === null || value === undefined) continue
    params.set(key, String(value))
  }
  return params.toString()
}

export default function JobCosting(props: JobCostingDashboardProps) {
  const { filters, canViewCosts, clients, jobs, teamMembers, alerts, can, settings, tab, jobRows, labor, billingReport, billing, trend, costBreakdown } = props
  // The filter form's edits, discarded whenever the server sends back new filters.
  const [draftState, setDraftState] = useState({ source: filters, value: filters })
  const draft = draftState.source === filters ? draftState.value : filters
  const setDraft = (value: JobCostingFilters) => setDraftState({ source: filters, value })
  const [isLoading, setIsLoading] = useState(false)
  // Any job's scope changing — estimate, change order, cost, invoice — refreshes the figures here.
  const { latest } = useCompanyScopeSync()
  const filterBar = useDisclosure(false)
  const settingsModal = useDisclosure(false)

  // A filter or tab change is a server round trip — show a placeholder rather than stale numbers.
  useEffect(() => {
    const offStart = router.on('start', () => setIsLoading(true))
    const offFinish = router.on('finish', () => setIsLoading(false))
    return () => {
      offStart()
      offFinish()
    }
  }, [])

  const visit = useCallback((query: string) => {
    router.get(query ? `${ROUTES.jobCosting}?${query}` : ROUTES.jobCosting, {}, { preserveScroll: true, preserveState: true })
  }, [])

  const applyFilters = useCallback((changes: Partial<JobCostingFilters>) => visit(toQuery({ ...filters, ...changes })), [filters, visit])

  const resetFilters = useCallback(() => visit(toQuery({ tab: filters.tab })), [filters.tab, visit])

  const applyPreset = useCallback((from: string | null, to: string | null) => applyFilters({ date_from: from, date_to: to }), [applyFilters])

  const exportReport = useCallback((format: 'csv' | 'xlsx') => {
    const query = toQuery(filters)
    window.open(`${routeTo.jobCostingExport(format)}${query ? `?${query}` : ''}`, '_blank')
  }, [filters])

  const activeChips = ([
    filters.job !== null && { label: `Job: ${jobs.find((j) => j.id === filters.job)?.name ?? filters.job}`, clear: { job: null } },
    filters.client !== 'all' && { label: `Client: ${filters.client}`, clear: { client: 'all' } },
    filters.status !== 'all' && { label: `Status: ${JOB_STATUS_LABEL[filters.status as keyof typeof JOB_STATUS_LABEL] ?? filters.status}`, clear: { status: 'all' } },
    filters.cost_type !== 'all' && { label: `Cost: ${filters.cost_type}`, clear: { cost_type: 'all' } },
    filters.team_member !== null && { label: `Member: ${teamMembers.find((m) => m.id === filters.team_member)?.name ?? filters.team_member}`, clear: { team_member: null } },
  ] as (FilterChip | false)[]).filter((chip): chip is FilterChip => chip !== false)

  const hasRange = filters.date_from !== null || filters.date_to !== null
  const tabs = TABS.filter((t) => canViewCosts || t.value !== 'billing')

  return (
    <PageTransition>
      <Head title="Analytics" />
      <LiveChangeBanner latest={latest} />

      <PageHeader
        title="Analytics"
        subtitle="Job costing and financial performance across your jobs"
        breadcrumbs={[{ label: 'Analytics' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="secondary" leftIcon={SlidersHorizontal} aria-expanded={filterBar.isOpen} onClick={filterBar.toggle}>
              Filter
            </Button>
            <span className="inline-flex items-center rounded-panel border border-hairline-strong bg-white/10 px-4 py-2 text-sm text-white/90">
              {hasRange ? `${filters.date_from ?? '…'} – ${filters.date_to ?? 'today'}` : 'All time'}
            </span>
            {can.manage && (
              <Button variant="secondary" leftIcon={Settings2} onClick={settingsModal.open}>
                Thresholds
              </Button>
            )}
            {canViewCosts && (
              <>
                <Button leftIcon={Download} onClick={() => exportReport('csv')}>Export CSV</Button>
                <Button variant="secondary" leftIcon={Download} onClick={() => exportReport('xlsx')}>Export XLSX</Button>
              </>
            )}
          </div>
        }
      />

      <AnimatePresence initial={false}>
        {filterBar.isOpen && (
          <motion.div
            key="filters"
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            transition={{ duration: MOTION.base }}
            className="mb-6 overflow-hidden rounded-card border border-hairline glass p-5 shadow-panel sm:p-6"
          >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <TextInput id="jc-date-from" type="date" label="Date From" value={draft.date_from ?? ''} onChange={(e) => setDraft({ ...draft, date_from: e.target.value || null })} />
              <TextInput id="jc-date-to" type="date" label="Date To" value={draft.date_to ?? ''} onChange={(e) => setDraft({ ...draft, date_to: e.target.value || null })} />
              <SelectField
                id="jc-job"
                label="Job"
                options={[{ label: 'All Jobs', value: 'all' }, ...jobs.map((job) => ({ label: job.name, value: String(job.id) }))]}
                value={draft.job !== null ? String(draft.job) : 'all'}
                onChange={(e) => setDraft({ ...draft, job: e.target.value === 'all' ? null : Number(e.target.value) })}
              />
              <SelectField
                id="jc-client"
                label="Client"
                options={[{ label: 'All Clients', value: 'all' }, ...clients.map((client) => ({ label: client, value: client }))]}
                value={draft.client}
                onChange={(e) => setDraft({ ...draft, client: e.target.value })}
              />
              <SelectField id="jc-status" label="Status" options={JOB_STATUS_FILTERS} value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })} />
              <SelectField id="jc-cost-type" label="Cost Type" options={JOB_COSTING_COST_TYPE_FILTERS} value={draft.cost_type} onChange={(e) => setDraft({ ...draft, cost_type: e.target.value })} />
              <SelectField
                id="jc-team-member"
                label="Team Member"
                options={[{ label: 'All Team Members', value: 'all' }, ...teamMembers.map((member) => ({ label: member.name, value: String(member.id) }))]}
                value={draft.team_member !== null ? String(draft.team_member) : 'all'}
                onChange={(e) => setDraft({ ...draft, team_member: e.target.value === 'all' ? null : Number(e.target.value) })}
              />
              <div className="flex items-end gap-3">
                <Button onClick={() => applyFilters(draft)}>Apply</Button>
                <Button variant="white" onClick={resetFilters}>Reset</Button>
              </div>
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      <div className="mb-4 flex flex-wrap items-center gap-2">
        {DATE_PRESETS.map((preset) => (
          <Button key={preset.label} size="sm" variant="secondary" onClick={() => applyPreset(...preset.range())}>
            {preset.label}
          </Button>
        ))}
        {activeChips.map((chip) => (
          <button
            key={chip.label}
            type="button"
            onClick={() => applyFilters(chip.clear)}
            className="inline-flex items-center gap-1.5 rounded-full border border-hairline-strong bg-white/10 px-3 py-1 text-sm text-white/90 hover:bg-white/15"
          >
            {chip.label}
            <X size={14} aria-hidden />
            <span className="sr-only">Remove filter</span>
          </button>
        ))}
      </div>

      <div className="mb-6">
        <SavedViews currentQuery={toQuery(filters)} onApply={visit} />
      </div>

      <p className="mb-6 text-sm text-white/70">
        {props.jobCount} active job{props.jobCount === 1 ? '' : 's'} in this view.
        {hasRange && ' Date range narrows actual hours and logged costs only; estimates and revenue are whole-job figures.'}
      </p>

      <FilterTabs
        options={tabs}
        value={tab}
        onChange={(value) => applyFilters({ tab: value })}
        counts={{ alerts: alerts.length }}
        className="mb-6"
      />

      {isLoading ? (
        <div className="grid gap-6" aria-busy="true" aria-label="Loading analytics">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {Array.from({ length: 8 }, (_, i) => <Skeleton key={i} className="h-24" />)}
          </div>
          <Skeleton className="h-64" />
          <div className="grid gap-6 xl:grid-cols-2">
            <Skeleton className="h-72" />
            <Skeleton className="h-72" />
          </div>
        </div>
      ) : (
        <>
          {tab === 'overview' && <OverviewTab props={props} onTab={(value) => applyFilters({ tab: value })} />}
          {tab === 'profitability' && <ProfitabilityTab rows={jobRows ?? []} canViewCosts={canViewCosts} />}
          {tab === 'labor' && labor && <LaborTab labor={labor} rows={jobRows ?? []} canViewCosts={canViewCosts} />}
          {tab === 'materials' && <MaterialsTab rows={jobRows ?? []} breakdown={costBreakdown} canViewCosts={canViewCosts} />}
          {tab === 'billing' && <BillingTab report={billingReport} billing={billing} trend={trend} />}
          {tab === 'alerts' && <AlertsTab alerts={alerts} />}
        </>
      )}

      <SettingsModal key={JSON.stringify(settings)} isOpen={settingsModal.isOpen} onClose={settingsModal.close} settings={settings} />
    </PageTransition>
  )
}

JobCosting.layout = appLayout
