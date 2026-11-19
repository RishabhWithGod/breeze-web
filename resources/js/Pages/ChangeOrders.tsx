import { Head, router, usePage } from '@inertiajs/react'
import { Eye, Pencil, Plus, Trash2, X, FileDiff } from 'lucide-react'
import { useState } from 'react'
import {
  Alert,
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  SelectField,
  Stat,
  Table,
  ToolbarSearch,
  UnderlineTabs,
} from '@/components/common'
import { ChangeOrderSourcePill, ChangeOrderStatusPill } from '@/components/changeOrders/ChangeOrderPills'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { ChangeOrderSource, ChangeOrderStatus, SharedPageProps } from '@/types'
import type { TableColumn } from '@/types'
import { formatCurrency } from '@/utils'

interface Row {
  readonly id: number
  readonly label: string
  readonly job: { readonly id: number; readonly name: string }
  readonly description: string | null
  readonly reasonLabel: string | null
  readonly customerRequested: boolean
  readonly source: ChangeOrderSource
  readonly laborHours: number
  readonly materialCost: number
  readonly amount: number
  readonly status: ChangeOrderStatus
  readonly canEdit: boolean
  readonly canDelete: boolean
}

export interface ChangeOrdersProps {
  orders: readonly Row[]
  page: { current: number; last: number; total: number; from: number; to: number }
  filters: { search: string; job: number | null; status: string; source: string }
  jobs: readonly { readonly id: number; readonly name: string }[]
  /** Per status, over everything the person can see — not just this page. */
  stats: Partial<Record<string, { count: number; sell: number }>>
  canCreate: boolean
}

const SOURCE_OPTIONS = [
  { value: 'all', label: 'All Sources' },
  { value: 'field', label: 'Field' },
  { value: 'office', label: 'Office' },
]

/**
 * Change Orders: added work, materials or labor documented after a job begins. Made by hand — no
 * drawing is needed — then submitted, and approved or rejected by a manager.
 */
export default function ChangeOrders({ orders, page, filters, jobs, stats, canCreate }: ChangeOrdersProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [search, setSearch] = useState(filters.search)
  const [deleting, setDeleting] = useState<Row | null>(null)

  const visit = (changes: Record<string, string | number | null>) => {
    const next = { ...filters, page: page.current, ...changes }
    const query: Record<string, string | number> = {}
    if (next.search) query['search'] = next.search
    if (next.job) query['job'] = next.job
    if (next.status !== 'all') query['status'] = next.status
    if (next.source !== 'all') query['source'] = next.source
    if (Number(next.page) > 1 && !Object.keys(changes).some((key) => key !== 'page')) query['page'] = Number(next.page)

    router.get(ROUTES.changeOrders, query, { preserveState: true, preserveScroll: true, replace: true })
  }

  const filtered = filters.search !== '' || filters.job !== null || filters.status !== 'all' || filters.source !== 'all'

  const count = (status: string) => stats[status]?.count ?? 0
  const sell = (status: string) => stats[status]?.sell ?? 0
  const total = Object.values(stats).reduce((sum, item) => sum + (item?.count ?? 0), 0)

  const columns: TableColumn<Row>[] = [
    {
      key: 'number',
      header: 'Change order #',
      render: (row) => <span className="font-semibold text-white">{row.label}</span>,
    },
    { key: 'job', header: 'Job', render: (row) => <span className="whitespace-nowrap">{row.job.name}</span> },
    { key: 'description', header: 'Description', render: (row) => <span className="block max-w-xs">{row.description ?? '—'}</span> },
    {
      key: 'reason',
      header: 'Reason',
      render: (row) => (
        <span className="whitespace-nowrap">
          {row.reasonLabel ?? '—'}
          {row.customerRequested && (
            <span className="ml-2 rounded-full bg-[var(--brand-bg)] px-2 py-0.5 text-xs font-semibold text-brand">
              Customer
            </span>
          )}
        </span>
      ),
    },
    { key: 'source', header: 'Source', render: (row) => <ChangeOrderSourcePill source={row.source} /> },
    { key: 'labor', header: 'Labor impact', render: (row) => <span className="whitespace-nowrap tabular-nums">{row.laborHours} hrs</span> },
    { key: 'material', header: 'Material impact', render: (row) => <span className="tabular-nums">{formatCurrency(row.materialCost)}</span> },
    { key: 'amount', header: 'Amount', render: (row) => <span className="font-semibold text-white tabular-nums">{formatCurrency(row.amount)}</span> },
    { key: 'status', header: 'Status', render: (row) => <ChangeOrderStatusPill status={row.status} /> },
    {
      key: 'actions',
      header: 'Actions',
      render: (row) => (
        <MoreMenu
          variant="minimal"
          ariaLabel={`Actions for ${row.label}`}
          items={[
            { label: 'View', icon: Eye, onSelect: () => router.visit(routeTo.changeOrder(row.id)) },
            ...(row.canEdit
              ? [{ label: 'Edit', icon: Pencil, onSelect: () => router.visit(routeTo.changeOrderEdit(row.id)) }]
              : []),
            ...(row.canDelete
              ? [{ label: 'Delete', icon: Trash2, destructive: true, onSelect: () => setDeleting(row) }]
              : []),
          ]}
        />
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title="Change Orders" />

      <PageHeader
        title="Change Orders"
        subtitle="Extra work after a job starts — priced, approved and billed. No drawing needed."
        actions={
          canCreate ? (
            <Button leftIcon={Plus} onClick={() => router.visit(ROUTES.changeOrderCreate)}>
              Create Change Order
            </Button>
          ) : undefined
        }
      />

      {flash.success && (
        <Alert key={flash.success} tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}
      {flash.warning && (
        <Alert key={flash.warning} tone="warning" className="mb-4">
          {flash.warning}
        </Alert>
      )}

      <div className="mb-5 grid grid-cols-2 gap-4 xl:grid-cols-4">
        <Stat
          label="Waiting for approval"
          value={formatCurrency(sell('submitted'), 0)}
          delta={`${count('submitted')} change ${count('submitted') === 1 ? 'order' : 'orders'}`}
          className="h-full"
        />
        <Stat
          label="Approved"
          value={formatCurrency(sell('approved'), 0)}
          delta={`${count('approved')} added to job value`}
          className="h-full"
        />
        <Stat label="Drafts" value={count('draft')} className="h-full" />
        <Stat label="Rejected" value={count('rejected')} className="h-full" />
      </div>

      <UnderlineTabs
        tabs={[
          { key: 'all', label: 'All', count: total },
          { key: 'draft', label: 'Draft', count: count('draft') },
          { key: 'submitted', label: 'Submitted', count: count('submitted') },
          { key: 'approved', label: 'Approved', count: count('approved') },
          { key: 'rejected', label: 'Rejected', count: count('rejected') },
        ]}
        value={filters.status}
        onChange={(next) => visit({ status: next, page: 1 })}
      />

      <Card padding="none" className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b border-hairline px-4 py-3">
          <ToolbarSearch
            value={search}
            onChange={setSearch}
            onSubmit={(value) => visit({ search: value.trim(), page: 1 })}
            applied={filters.search !== ''}
            placeholder="Search by job, description, or number"
          />
          <div className="ml-auto flex flex-wrap items-center gap-3">
            <SelectField
              aria-label="Filter by job"
              className="w-44"
              value={filters.job === null ? '' : String(filters.job)}
              onChange={(event) =>
                visit({ job: event.target.value === '' ? null : Number(event.target.value), page: 1 })
              }
              options={[
                { value: '', label: 'All Jobs' },
                ...jobs.map((job) => ({ value: String(job.id), label: job.name })),
              ]}
            />
            <SelectField
              aria-label="Filter by source"
              className="w-36"
              value={filters.source}
              onChange={(event) => visit({ source: event.target.value, page: 1 })}
              options={SOURCE_OPTIONS}
            />
            {filtered && (
              <Button
                type="button"
                variant="ghost"
                leftIcon={X}
                onClick={() => {
                  setSearch('')
                  router.get(ROUTES.changeOrders, {}, { replace: true })
                }}
              >
                Clear
              </Button>
            )}
            <span className="text-[13px] text-[var(--text-3)]">
              {page.total} {page.total === 1 ? 'change order' : 'change orders'}
            </span>
          </div>
        </div>

        {orders.length === 0 ? (
          <div className="p-6">
            <EmptyState
              icon={FileDiff}
              title={filtered ? 'No change orders match' : 'No change orders yet'}
              description={
                filtered
                  ? 'Try a different search or filter.'
                  : 'When work is added to a job after it begins, document it here so it can be approved and billed.'
              }
              {...(!filtered && canCreate
                ? {
                    action: (
                      <Button leftIcon={Plus} onClick={() => router.visit(ROUTES.changeOrderCreate)}>
                        Create Change Order
                      </Button>
                    ),
                  }
                : {})}
            />
          </div>
        ) : (
          <Table
            variant="lined"
            columns={columns}
            rows={orders}
            getRowId={(row) => row.id}
            caption="Change orders"
            onRowClick={(row) => router.visit(routeTo.changeOrder(row.id))}
          />
        )}
      </Card>

      {page.total > 0 && (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-[var(--text-3)]">
            Showing {page.from} - {page.to} of {page.total} change {page.total === 1 ? 'order' : 'orders'}
          </p>
          {page.last > 1 && (
            <Pagination
              tone="light"
              withLabels
              page={page.current}
              pageCount={page.last}
              onPageChange={(next) => visit({ page: next })}
            />
          )}
        </div>
      )}

      <ConfirmDialog
        isOpen={deleting !== null}
        title="Delete this change order?"
        description={
          deleting ? `${deleting.label} and its evidence will be removed. Only drafts can be deleted.` : undefined
        }
        confirmLabel="Delete"
        confirmVariant="danger"
        tone="danger"
        onConfirm={() => {
          if (deleting) router.delete(routeTo.changeOrder(deleting.id), { onFinish: () => setDeleting(null) })
        }}
        onCancel={() => setDeleting(null)}
      />
    </PageTransition>
  )
}

ChangeOrders.layout = appLayout
