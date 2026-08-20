import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, Plus, Receipt, Trash2 } from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  IconButton,
  SectionHeading,
  SelectField,
  StatusChip,
  Table,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { JOB_COST_ENTRY_CATEGORY_LABEL, ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  JobCostEntryRow,
  JobCostRow,
  JobCostingEstimatedItemRow,
  JobCostingLaborRow,
  JobCostingScheduleInfo,
  SharedPageProps,
  TableColumn,
} from '@/types'
import { JOB_STATUS_LABEL, JOB_STATUS_TONE, formatCurrency, formatDate, formatHours } from '@/utils'

export interface JobCostingDetailProps {
  job: { id: number; name: string; client: string | null; status: string }
  range: { from: string | null; to: string | null }
  canViewCosts: boolean
  summary: JobCostRow
  laborRows: readonly JobCostingLaborRow[]
  costEntries: readonly JobCostEntryRow[]
  estimatedItems: readonly JobCostingEstimatedItemRow[]
  schedule: JobCostingScheduleInfo
  can: { manage: boolean }
}

const EMPTY_DRAFT = { category: 'material' as const, description: '', quantity: '', unit_cost: '', amount: '', incurred_on: new Date().toISOString().slice(0, 10), notes: '' }

export default function JobCostingDetail({
  job,
  canViewCosts,
  summary,
  laborRows,
  costEntries,
  estimatedItems,
  schedule,
  can,
}: JobCostingDetailProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [draft, setDraft] = useState(EMPTY_DRAFT)
  const addEntry = useDisclosure()

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  const submitEntry = () => {
    router.post(routeTo.jobCostEntries(job.id), {
      category: draft.category,
      description: draft.description,
      quantity: draft.quantity || null,
      unit_cost: draft.unit_cost || null,
      amount: draft.amount,
      incurred_on: draft.incurred_on,
      notes: draft.notes || null,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setDraft(EMPTY_DRAFT)
        addEntry.close()
      },
    })
  }

  const deleteEntry = (entry: JobCostEntryRow) => {
    router.delete(routeTo.jobCostEntry(job.id, entry.id), { preserveScroll: true })
  }

  const breakdown = [
    { label: 'Labor', estimated: summary.estimatedLaborCost, actual: summary.actualLaborCost, variance: summary.laborCostVariance },
    { label: 'Materials', estimated: summary.estimatedMaterialCost, actual: summary.actualMaterialCost, variance: summary.materialCostVariance },
    { label: 'Equipment', estimated: summary.estimatedEquipmentCost, actual: summary.actualEquipmentCost, variance: summary.equipmentCostVariance },
    { label: 'Other', estimated: summary.estimatedOtherCost, actual: summary.actualOtherCost, variance: summary.otherCostVariance },
  ]

  const laborColumns: TableColumn<JobCostingLaborRow>[] = [
    { key: 'name', header: 'Team Member', render: (row) => <span className="text-white">{row.name}</span> },
    { key: 'role', header: 'Role', render: (row) => <span className="text-white/85">{row.role ?? '—'}</span> },
    { key: 'hours', header: 'Hours', align: 'right', render: (row) => formatHours(row.hours) },
    { key: 'regular', header: 'Regular', align: 'right', render: (row) => formatHours(row.regularHours) },
    { key: 'ot', header: 'Overtime', align: 'right', render: (row) => formatHours(row.overtimeHours) },
    { key: 'billable', header: 'Billable Hours', align: 'right', render: (row) => formatHours(row.billableHours) },
    ...(canViewCosts ? [
      { key: 'rate', header: 'Labor Rate', align: 'right' as const, render: (row: JobCostingLaborRow) => row.laborRate !== null ? `${formatCurrency(row.laborRate, 2)}/hr` : '—' },
      { key: 'cost', header: 'Labor Cost', align: 'right' as const, render: (row: JobCostingLaborRow) => formatCurrency(row.laborCost, 2) },
    ] : []),
  ]

  const entryColumns: TableColumn<JobCostEntryRow>[] = [
    { key: 'date', header: 'Date', render: (row) => formatDate(row.incurredOn) },
    { key: 'category', header: 'Category', render: (row) => <Badge>{JOB_COST_ENTRY_CATEGORY_LABEL[row.category]}</Badge> },
    { key: 'description', header: 'Description', render: (row) => <span className="text-white">{row.description}</span> },
    { key: 'quantity', header: 'Qty', align: 'right', render: (row) => row.quantity ?? '—' },
    { key: 'amount', header: 'Amount', align: 'right', render: (row) => formatCurrency(row.amount, 2) },
    { key: 'recordedBy', header: 'Recorded By', render: (row) => row.recordedBy ?? '—' },
    ...(can.manage ? [{
      key: 'actions', header: '', render: (row: JobCostEntryRow) => (
        <IconButton icon={Trash2} label={`Remove ${row.description}`} size="sm" variant="danger" onClick={() => deleteEntry(row)} />
      ),
    }] : []),
  ]

  return (
    <PageTransition>
      <Head title={`Job Costing — ${job.name}`} />

      <PageHeader
        title="Job Costing"
        subtitle={`${job.name}${job.client ? ` · ${job.client}` : ''}`}
        breadcrumbs={[
          { label: 'Job Costing', href: ROUTES.jobCosting },
          { label: 'Jobs', href: ROUTES.jobs },
          { label: job.name },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <StatusChip tone={JOB_STATUS_TONE[job.status as keyof typeof JOB_STATUS_TONE] ?? 'neutral'} label={JOB_STATUS_LABEL[job.status as keyof typeof JOB_STATUS_LABEL] ?? job.status} />
            <ButtonLink href={routeTo.job(job.id)} variant="secondary" leftIcon={ArrowLeft}>
              Back to Job
            </ButtonLink>
          </div>
        }
      />

      <AnimatePresence initial={false}>
        {notice && (
          <Alert key={notice} tone={flash.warning ? 'warning' : 'success'} className="mb-6" onDismiss={() => setDismissed(notice)}>
            {notice}
          </Alert>
        )}
      </AnimatePresence>

      {canViewCosts ? (
        <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
          <Stat label="Estimated Cost" value={formatCurrency(summary.estimatedTotalCost, 0)} />
          <Stat label="Actual Cost" value={formatCurrency(summary.actualTotalCost, 0)} />
          <Stat label="Remaining Budget" value={formatCurrency(Math.max(0, summary.estimatedTotalCost - summary.actualTotalCost), 0)} />
          <Stat
            label="Variance"
            value={`${summary.totalCostVariance >= 0 ? '+' : ''}${formatCurrency(summary.totalCostVariance, 0)}`}
            tone={summary.totalCostVariance > 0 ? 'danger' : 'success'}
          />
          <Stat label="Profit" value={formatCurrency(summary.profit, 0)} tone={summary.profit >= 0 ? 'success' : 'danger'} />
          <Stat label="Margin" value={summary.marginPct !== null ? `${summary.marginPct}%` : '—'} tone={summary.marginPct !== null && summary.marginPct < 0 ? 'danger' : 'success'} />
        </div>
      ) : (
        <Alert tone="info" className="mb-6" title="Cost figures are restricted">
          Only a Project Manager, Admin or Owner can see this job's dollar figures. Hours and status are shown below.
        </Alert>
      )}

      {/* ================================================ Cost breakdown ======= */}
      {canViewCosts && (
        <Card padding="lg" className="mb-6">
          <SectionHeading as="h3" title="Cost Breakdown" />
          <div className="overflow-x-auto">
            <table className="w-full text-left">
              <thead>
                <tr className="text-2xs tracking-wide text-white/70 uppercase">
                  <th className="py-2">Category</th>
                  <th className="py-2 text-right">Estimated</th>
                  <th className="py-2 text-right">Actual</th>
                  <th className="py-2 text-right">Variance</th>
                  <th className="py-2 text-right">%</th>
                </tr>
              </thead>
              <tbody>
                {breakdown.map((row) => {
                  const pct = row.estimated > 0 ? (row.variance / row.estimated) * 100 : null
                  return (
                    <tr key={row.label} className="border-t border-hairline">
                      <td className="py-3 text-white">{row.label}</td>
                      <td className="py-3 text-right text-white/90">{formatCurrency(row.estimated, 2)}</td>
                      <td className="py-3 text-right text-white/90">{formatCurrency(row.actual, 2)}</td>
                      <td className={`py-3 text-right font-medium ${row.variance > 0 ? 'text-status-danger' : 'text-status-success'}`}>
                        {row.variance >= 0 ? '+' : ''}{formatCurrency(row.variance, 2)}
                      </td>
                      <td className={`py-3 text-right font-medium ${row.variance > 0 ? 'text-status-danger' : 'text-status-success'}`}>
                        {pct !== null ? `${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%` : '—'}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* =================================================== Labor details ==== */}
      <Card padding="lg" className="mb-6">
        <SectionHeading as="h3" title="Labor Details" subtitle="Only approved time entries count toward actual cost" />
        {laborRows.length === 0 ? (
          <p className="text-md text-white/70">No approved time has been logged on this job yet.</p>
        ) : (
          <Table dense variant="lined" headerVariant="plain" columns={laborColumns} rows={laborRows} getRowId={(row) => row.name} caption="Labor details" />
        )}
      </Card>

      {/* ============================================ Material/equipment/other = */}
      <Card padding="lg" className="mb-6">
        <SectionHeading
          as="h3"
          title="Material, Equipment & Other Costs"
          subtitle="Actual costs recorded against this job — there is no purchasing system to pull these from automatically"
          actions={can.manage ? (
            <Button size="sm" leftIcon={Plus} onClick={addEntry.open}>Log Actual Cost</Button>
          ) : undefined}
        />

        {addEntry.isOpen && (
          <div className="mb-4 grid gap-2 rounded-panel bg-white/8 p-3 sm:grid-cols-6">
            <SelectField id="entry-category" aria-label="Category" options={[
              { label: 'Material', value: 'material' },
              { label: 'Equipment', value: 'equipment' },
              { label: 'Other', value: 'other' },
            ]} value={draft.category} onChange={(event) => setDraft({ ...draft, category: event.target.value as typeof draft.category })} />
            <TextInput id="entry-description" aria-label="Description" placeholder="Description" className="sm:col-span-2" value={draft.description} onChange={(event) => setDraft({ ...draft, description: event.target.value })} />
            <TextInput id="entry-quantity" aria-label="Quantity" type="number" min={0} placeholder="Qty" value={draft.quantity} onChange={(event) => setDraft({ ...draft, quantity: event.target.value })} />
            <TextInput id="entry-amount" aria-label="Amount" type="number" min={0} placeholder="Amount" value={draft.amount} onChange={(event) => setDraft({ ...draft, amount: event.target.value })} />
            <TextInput id="entry-date" aria-label="Date incurred" type="date" value={draft.incurred_on} onChange={(event) => setDraft({ ...draft, incurred_on: event.target.value })} />
            <div className="col-span-full flex gap-2">
              <Button size="sm" leftIcon={Receipt} onClick={submitEntry} disabled={!draft.description || !draft.amount}>Save</Button>
              <Button size="sm" variant="ghost" onClick={addEntry.close}>Cancel</Button>
            </div>
          </div>
        )}

        {costEntries.length === 0 ? (
          <p className="text-md text-white/70">No actual material, equipment or other costs have been logged yet.</p>
        ) : (
          <Table dense variant="lined" headerVariant="plain" columns={entryColumns} rows={costEntries} getRowId={(row) => row.id} caption="Actual cost entries" />
        )}

        {estimatedItems.length > 0 && (
          <div className="mt-6 border-t border-hairline pt-4">
            <p className="mb-2 text-2xs tracking-wide text-white/70 uppercase">Estimated (from the estimate)</p>
            <ul className="flex flex-col gap-1.5">
              {estimatedItems.map((item, index) => (
                <li key={index} className="flex items-center justify-between rounded-panel bg-white/5 px-3 py-2 text-md">
                  <span className="text-white/90">{item.description}</span>
                  <span className="text-white/70">{item.quantity} · {formatCurrency(item.cost, 2)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </Card>

      {/* ============================================= Estimate + Billing ====== */}
      <div className="grid gap-6 xl:grid-cols-2">
        <Card padding="lg">
          <SectionHeading
            as="h3"
            title="Estimate Comparison"
            actions={summary.estimateId ? <ButtonLink href={routeTo.estimate(summary.estimateId)} variant="secondary" size="sm">Open Estimate</ButtonLink> : undefined}
          />
          {summary.estimateId ? (
            <dl className="grid grid-cols-2 gap-4">
              <Field label="Estimate" value={summary.estimateNumber ?? '—'} />
              <Field label="Estimated Labor" value={formatCurrency(summary.estimatedLaborCost, 2)} />
              <Field label="Estimated Materials" value={formatCurrency(summary.estimatedMaterialCost, 2)} />
              <Field label="Estimated Total" value={formatCurrency(summary.estimatedTotalCost, 2)} />
              <Field label="Actual Labor" value={formatCurrency(summary.actualLaborCost, 2)} />
              <Field label="Actual Materials" value={formatCurrency(summary.actualMaterialCost, 2)} />
              <Field label="Actual Total" value={formatCurrency(summary.actualTotalCost, 2)} />
              <Field label="Variance" value={`${summary.totalCostVariance >= 0 ? '+' : ''}${formatCurrency(summary.totalCostVariance, 2)}`} />
            </dl>
          ) : (
            <p className="text-md text-white/70">This job has no estimate to compare against.</p>
          )}
        </Card>

        <Card padding="lg">
          <SectionHeading
            as="h3"
            title="Billing"
            actions={<ButtonLink href={`${ROUTES.invoices}?job=${job.id}`} variant="secondary" size="sm">View Invoices</ButtonLink>}
          />
          {canViewCosts ? (
            <dl className="grid grid-cols-2 gap-4">
              <Field label="Revenue" value={formatCurrency(summary.revenue, 2)} />
              <Field label="Billed" value={formatCurrency(summary.billed, 2)} />
              <Field label="Paid" value={formatCurrency(summary.paid, 2)} />
              <Field label="Outstanding" value={formatCurrency(summary.outstanding, 2)} />
              <Field label="Unbilled" value={formatCurrency(summary.unbilled, 2)} />
            </dl>
          ) : (
            <p className="text-md text-white/70">Billing figures are restricted.</p>
          )}
        </Card>
      </div>

      {/* ================================================ Schedule ============= */}
      <Card padding="lg" className="mt-6">
        <SectionHeading as="h3" title="Schedule" />
        {schedule.hasSchedule ? (
          <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Field label="Scheduled Hours" value={formatHours(schedule.scheduledHours ?? 0)} />
            <Field label="Estimated Hours" value={formatHours(summary.estimatedLaborHours)} />
            <Field label="Actual Hours" value={formatHours(summary.actualLaborHours)} />
            <Field label="Remaining Hours" value={formatHours(schedule.remainingHours)} />
          </dl>
        ) : (
          <p className="text-md text-white/70">This job has not been scheduled yet.</p>
        )}
      </Card>
    </PageTransition>
  )
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: 'success' | 'danger' }) {
  return (
    <div className="rounded-panel border border-hairline bg-white/4 p-4">
      <p className="text-2xs tracking-wide text-white/70 uppercase">{label}</p>
      <p className={`mt-1 text-xl font-bold ${tone === 'danger' ? 'text-status-danger' : tone === 'success' ? 'text-status-success' : 'text-white'}`}>
        {value}
      </p>
    </div>
  )
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-2xs tracking-wide text-white/80 uppercase">{label}</dt>
      <dd className="mt-1 truncate text-md text-white" title={value}>{value}</dd>
    </div>
  )
}

JobCostingDetail.layout = appLayout
