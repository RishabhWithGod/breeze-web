import { Head, Link, router, usePage } from '@inertiajs/react'
import { Eye, FilePen, Pencil, Plus, Search, Trash2, X } from 'lucide-react'
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
  TextInput,
} from '@/components/common'
import { ChangeOrderSourcePill, ChangeOrderStatusPill } from '@/components/changeOrders/ChangeOrderPills'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { ChangeOrderSource, ChangeOrderStatus, SharedPageProps } from '@/types'
import { formatCurrency } from '@/utils'

interface Row {
  readonly id: number
  readonly label: string
  readonly job: { readonly id: number; readonly name: string }
  readonly description: string
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
  canCreate: boolean
}

const STATUS_OPTIONS = [
  { value: 'all', label: 'All Statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'submitted', label: 'Submitted' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
]

const SOURCE_OPTIONS = [
  { value: 'all', label: 'All Sources' },
  { value: 'field', label: 'Field' },
  { value: 'office', label: 'Office' },
]

/**
 * Change Orders: added work, materials or labor documented after a job begins. Made by hand — no
 * drawing is needed — then submitted, and approved or rejected by a manager.
 */
export default function ChangeOrders({ orders, page, filters, jobs, canCreate }: ChangeOrdersProps) {
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

  return (
    <PageTransition>
      <Head title="Change Orders" />

      <Card padding="none" className="overflow-hidden">
        <PageHeader
          title="Change Orders"
          subtitle="Manage change orders for your electrical jobs"
          className="mb-0 border-b border-hairline p-4 sm:p-5"
          actions={
            canCreate ? (
              <Button leftIcon={Plus} onClick={() => router.visit(ROUTES.changeOrderCreate)}>
                Create Change Order
              </Button>
            ) : undefined
          }
        />

        <div className="p-4 sm:p-5">
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

          <form
            className="mb-4 flex flex-wrap items-center justify-between gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              visit({ search: search.trim(), page: 1 })
            }}
          >
            <TextInput
              id="change-order-search"
              aria-label="Search change orders by job, description, or number"
              placeholder="Search change orders by job, description, or number..."
              leftIcon={Search}
              className="w-full lg:max-w-md lg:flex-1"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              onBlur={() => search.trim() !== filters.search && visit({ search: search.trim(), page: 1 })}
            />
            <div className="flex flex-wrap items-center gap-3">
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
                aria-label="Filter by status"
                className="w-40"
                value={filters.status}
                onChange={(event) => visit({ status: event.target.value, page: 1 })}
                options={STATUS_OPTIONS}
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
            </div>
          </form>

          {orders.length === 0 ? (
            <EmptyState
              icon={FilePen}
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
          ) : (
            <div className="overflow-x-auto rounded-panel border border-hairline">
              <table className="w-full min-w-[68rem] text-left text-sm">
                <thead>
                  <tr className="border-b border-hairline bg-white/6 text-xs font-semibold text-white/90">
                    <th className="px-4 py-3">Change Order #</th>
                    <th className="px-4 py-3">Job</th>
                    <th className="px-4 py-3">Description</th>
                    <th className="px-4 py-3">Reason</th>
                    <th className="px-4 py-3">Source</th>
                    <th className="px-4 py-3">Labor Impact</th>
                    <th className="px-4 py-3">Material Impact</th>
                    <th className="px-4 py-3">Amount</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-center">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-hairline text-white/90">
                  {orders.map((row) => (
                    <tr
                      key={row.id}
                      className="cursor-pointer hover:bg-white/4"
                      onClick={() => router.visit(routeTo.changeOrder(row.id))}
                    >
                      <td className="px-4 py-3">
                        <Link href={routeTo.changeOrder(row.id)} className="font-medium text-white hover:text-brand">
                          {row.label}
                        </Link>
                      </td>
                      <td className="px-4 py-3">{row.job.name}</td>
                      <td className="max-w-xs px-4 py-3">{row.description}</td>
                      <td className="px-4 py-3">
                        {row.reasonLabel ?? '—'}
                        {row.customerRequested && (
                          <span className="ml-2 rounded-full bg-brand/20 px-2 py-0.5 text-xs font-semibold text-white">
                            Customer
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <ChangeOrderSourcePill source={row.source} />
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap tabular-nums">{row.laborHours} hrs</td>
                      <td className="px-4 py-3 tabular-nums">{formatCurrency(row.materialCost)}</td>
                      <td className="px-4 py-3 tabular-nums">{formatCurrency(row.amount)}</td>
                      <td className="px-4 py-3">
                        <ChangeOrderStatusPill status={row.status} />
                      </td>
                      <td className="px-4 py-3 text-center" onClick={(event) => event.stopPropagation()}>
                        <MoreMenu
                          variant="minimal"
                          ariaLabel={`Actions for ${row.label}`}
                          items={[
                            { label: 'View', icon: Eye, onSelect: () => router.visit(routeTo.changeOrder(row.id)) },
                            ...(row.canEdit
                              ? [
                                  {
                                    label: 'Edit',
                                    icon: Pencil,
                                    onSelect: () => router.visit(routeTo.changeOrderEdit(row.id)),
                                  },
                                ]
                              : []),
                            ...(row.canDelete
                              ? [{ label: 'Delete', icon: Trash2, destructive: true, onSelect: () => setDeleting(row) }]
                              : []),
                          ]}
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {page.total > 0 && (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-white/85">
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
        </div>
      </Card>

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
