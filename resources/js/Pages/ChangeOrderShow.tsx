import { Head, Link, router, usePage } from '@inertiajs/react'
import { Check, FileText, History, Pencil, Paperclip, Send, Trash2, Undo2, Upload, X } from 'lucide-react'
import { useRef, useState } from 'react'
import { Alert, Button, Card, ConfirmDialog, Modal, TextArea } from '@/components/common'
import { ChangeOrderSourcePill, ChangeOrderStatusPill } from '@/components/changeOrders/ChangeOrderPills'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { ChangeOrderLineKind, ChangeOrderSummary, SharedPageProps } from '@/types'
import { formatCurrency, formatModified } from '@/utils'

interface Detail extends ChangeOrderSummary {
  readonly job: { readonly id: number; readonly name: string; readonly client: string | null }
  readonly reason: string | null
  readonly markupPct: number
  readonly laborCost: number
  readonly costTotal: number
  readonly author: string | null
  readonly submittedAt: string | null
  readonly decidedAt: string | null
  readonly decidedBy: string | null
  readonly decisionNote: string | null
  readonly createdAt: string
  readonly lines: readonly {
    readonly id: number
    readonly kind: ChangeOrderLineKind
    readonly description: string
    readonly quantity: number
    readonly unit: string | null
    readonly unitCost: number
    readonly total: number
  }[]
  readonly attachments: readonly {
    readonly id: number
    readonly name: string
    readonly size: number
    readonly mime: string | null
    readonly at: string
  }[]
  readonly history: readonly {
    readonly id: number
    readonly type: string
    readonly note: string | null
    readonly amount: number | null
    readonly by: string | null
    readonly at: string
  }[]
  readonly billing: {
    readonly state: 'none' | 'pending' | 'billed'
    readonly invoices: readonly { readonly id: number; readonly number: string; readonly status: string }[]
  }
  readonly can: { edit: boolean; submit: boolean; withdraw: boolean; decide: boolean; delete: boolean }
}

export interface ChangeOrderShowProps {
  changeOrder: Detail
}

const EVENT_LABEL: Record<string, string> = {
  created: 'Created',
  updated: 'Changed',
  submitted: 'Submitted for approval',
  withdrawn: 'Taken back to draft',
  approved: 'Approved',
  rejected: 'Rejected',
}

const size = (bytes: number) =>
  bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`

/** One change order: what was added, what it costs and sells for, its evidence, and its history. */
export default function ChangeOrderShow({ changeOrder: co }: ChangeOrderShowProps) {
  const { flash, errors } = usePage<SharedPageProps>().props
  const fieldErrors = errors as Record<string, string | undefined>
  const fileInput = useRef<HTMLInputElement>(null)
  const [deciding, setDeciding] = useState<'approve' | 'reject' | null>(null)
  const [note, setNote] = useState('')
  const [deleting, setDeleting] = useState(false)
  const [busy, setBusy] = useState(false)

  const act = (action: 'submit' | 'withdraw', data: Record<string, string> = {}) =>
    router.post(routeTo.changeOrderAction(co.id, action), data, { preserveScroll: true })

  const decide = () => {
    if (deciding === null) return
    router.post(
      routeTo.changeOrderAction(co.id, deciding),
      { note },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: () => {
          setDeciding(null)
          setNote('')
        },
      },
    )
  }

  const lines = (kind: ChangeOrderLineKind) => co.lines.filter((line) => line.kind === kind)
  const canAddEvidence = co.can.edit || co.can.withdraw

  const table = (title: string, kind: ChangeOrderLineKind) => {
    const rows = lines(kind)
    const isLabor = kind === 'labor'

    return (
      <Card padding="md" className="mt-4">
        <h2 className="mb-3 text-lg font-semibold text-white">{title}</h2>
        {rows.length === 0 ? (
          <p className="text-sm text-white/70">No {isLabor ? 'labor' : 'material'} lines.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[30rem] text-left text-sm">
              <thead>
                <tr className="border-b border-hairline text-xs font-semibold text-white/75">
                  <th className="py-2 pr-3">Description</th>
                  <th className="px-3 py-2 text-right">{isLabor ? 'Hours' : 'Quantity'}</th>
                  <th className="px-3 py-2 text-right">{isLabor ? 'Rate' : 'Unit cost'}</th>
                  <th className="py-2 pl-3 text-right">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-hairline text-white/90">
                {rows.map((line) => (
                  <tr key={line.id}>
                    <td className="py-2 pr-3">{line.description}</td>
                    <td className="px-3 py-2 text-right tabular-nums">
                      {line.quantity} {line.unit ?? ''}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(line.unitCost, 2)}</td>
                    <td className="py-2 pl-3 text-right tabular-nums">{formatCurrency(line.total, 2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    )
  }

  return (
    <PageTransition>
      <Head title={co.label} />

      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {co.label}
            <ChangeOrderStatusPill status={co.status} />
            <ChangeOrderSourcePill source={co.source} />
          </span>
        }
        subtitle={co.description}
        breadcrumbs={[{ label: 'Change Orders', href: ROUTES.changeOrders }, { label: co.label }]}
        className="mb-5"
        actions={
          <>
            {co.can.edit && (
              <Button
                variant="secondary"
                leftIcon={Pencil}
                onClick={() => router.visit(routeTo.changeOrderEdit(co.id))}
              >
                Edit
              </Button>
            )}
            {co.can.submit && (
              <Button leftIcon={Send} onClick={() => act('submit')}>
                {co.status === 'rejected' ? 'Resubmit' : 'Submit for approval'}
              </Button>
            )}
            {co.can.withdraw && (
              <Button variant="secondary" leftIcon={Undo2} onClick={() => act('withdraw')}>
                Withdraw
              </Button>
            )}
            {co.can.decide && (
              <>
                <Button variant="secondary" leftIcon={X} onClick={() => setDeciding('reject')}>
                  Reject
                </Button>
                <Button leftIcon={Check} onClick={() => setDeciding('approve')}>
                  Approve
                </Button>
              </>
            )}
            {co.can.delete && (
              <Button variant="ghost" leftIcon={Trash2} onClick={() => setDeleting(true)}>
                Delete
              </Button>
            )}
          </>
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
      {(fieldErrors['lines'] || fieldErrors['attachments.0']) && (
        <Alert tone="danger" className="mb-4">
          {fieldErrors['lines'] ?? fieldErrors['attachments.0']}
        </Alert>
      )}
      {co.status === 'rejected' && co.decisionNote && (
        <Alert tone="warning" title={`Rejected${co.decidedBy ? ` by ${co.decidedBy}` : ''}`} className="mb-4">
          {co.decisionNote}
        </Alert>
      )}
      {co.status === 'approved' && (
        <Alert tone="success" title="Approved" className="mb-4">
          {co.billing.state === 'billed' ? (
            <span>
              {formatCurrency(co.amount, 2)} is on the job's billing:{' '}
              {co.billing.invoices.map((invoice, index) => (
                <span key={invoice.id}>
                  {index > 0 && ', '}
                  <Link href={`/invoices/${invoice.id}`} className="font-semibold underline">
                    {invoice.number}
                  </Link>
                </span>
              ))}
              .
            </span>
          ) : (
            `${formatCurrency(co.amount, 2)} will be added to this job's invoice when it is raised.`
          )}
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <div>
          <Card padding="md">
            <dl className="grid gap-4 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-white/70">Job</dt>
                <dd className="mt-0.5 font-medium text-white">
                  <Link href={routeTo.job(co.job.id)} className="hover:text-brand">
                    {co.job.name}
                  </Link>
                  {co.job.client && <span className="font-normal text-white/70"> · {co.job.client}</span>}
                </dd>
              </div>
              <div>
                <dt className="text-white/70">Raised by</dt>
                <dd className="mt-0.5 font-medium text-white">
                  {co.author ?? '—'} <span className="font-normal text-white/70">· {formatModified(co.createdAt)}</span>
                </dd>
              </div>
              <div className="sm:col-span-2">
                <dt className="text-white/70">Description and reason</dt>
                <dd className="mt-0.5 whitespace-pre-line text-white">
                  {co.description}
                  {co.reason && <span className="mt-1 block text-white/80">{co.reason}</span>}
                </dd>
              </div>
            </dl>
          </Card>

          {table('Materials', 'material')}
          {table('Labor', 'labor')}
        </div>

        <aside className="space-y-4">
          <Card padding="md">
            <h2 className="mb-3 text-lg font-semibold text-white">Cost and sell impact</h2>
            <dl className="space-y-2 text-sm">
              {(
                [
                  ['Labor', `${co.laborHours} hrs · ${formatCurrency(co.laborCost, 2)}`],
                  ['Materials', formatCurrency(co.materialCost, 2)],
                  ['Cost', formatCurrency(co.costTotal, 2)],
                  ['Markup', `${co.markupPct}%`],
                ] as const
              ).map(([label, value]) => (
                <div key={label} className="flex justify-between gap-3">
                  <dt className="text-white/75">{label}</dt>
                  <dd className="text-white tabular-nums">{value}</dd>
                </div>
              ))}
            </dl>
            <div className="mt-4 flex items-center justify-between border-t border-hairline pt-3">
              <span className="font-semibold text-white">Sell amount</span>
              <span className="text-xl font-bold text-white tabular-nums">{formatCurrency(co.amount, 2)}</span>
            </div>
          </Card>

          <Card padding="md">
            <div className="mb-3 flex items-center justify-between gap-3">
              <h2 className="text-lg font-semibold text-white">Evidence</h2>
              {canAddEvidence && (
                <>
                  <input
                    ref={fileInput}
                    type="file"
                    multiple
                    aria-label="Evidence files"
                    className="sr-only"
                    onChange={(event) => {
                      const chosen = Array.from(event.target.files ?? [])
                      event.target.value = ''
                      if (chosen.length > 0)
                        router.post(
                          routeTo.changeOrderAction(co.id, 'attachments'),
                          { attachments: chosen },
                          { forceFormData: true, preserveScroll: true },
                        )
                    }}
                  />
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    leftIcon={Upload}
                    onClick={() => fileInput.current?.click()}
                  >
                    Add
                  </Button>
                </>
              )}
            </div>
            {co.attachments.length === 0 ? (
              <p className="flex items-center gap-2 text-sm text-white/70">
                <Paperclip size={14} aria-hidden /> No evidence attached.
              </p>
            ) : (
              <ul className="space-y-1.5">
                {co.attachments.map((file) => (
                  <li
                    key={file.id}
                    className="flex items-center gap-2 rounded-panel border border-hairline bg-white/4 px-3 py-1.5 text-sm"
                  >
                    <FileText size={14} aria-hidden className="shrink-0 text-brand" />
                    <a
                      href={routeTo.changeOrderFile(co.id, file.id)}
                      className="min-w-0 flex-1 truncate text-white hover:text-brand"
                    >
                      {file.name}
                    </a>
                    <span className="text-2xs text-white/60">{size(file.size)}</span>
                    {canAddEvidence && (
                      <button
                        type="button"
                        aria-label={`Remove ${file.name}`}
                        onClick={() => router.delete(routeTo.changeOrderFile(co.id, file.id), { preserveScroll: true })}
                        className="text-white/70 hover:text-white"
                      >
                        <X size={14} aria-hidden />
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card padding="md">
            <h2 className="mb-3 flex items-center gap-2 text-lg font-semibold text-white">
              <History size={17} aria-hidden /> History
            </h2>
            <ol className="space-y-3">
              {co.history.map((event) => (
                <li key={event.id} className="border-l-2 border-brand/50 pl-3 text-sm">
                  <p className="font-medium text-white">
                    {EVENT_LABEL[event.type] ?? event.type}
                    {event.amount !== null && (
                      <span className="font-normal text-white/70"> · {formatCurrency(event.amount, 2)}</span>
                    )}
                  </p>
                  {event.note && <p className="mt-0.5 text-white/85">“{event.note}”</p>}
                  <p className="mt-0.5 text-xs text-white/65">
                    {event.by ?? 'Someone'} · {formatModified(event.at)}
                  </p>
                </li>
              ))}
            </ol>
          </Card>
        </aside>
      </div>

      {deciding !== null && (
        <Modal
          isOpen
          onClose={() => setDeciding(null)}
          title={deciding === 'approve' ? `Approve ${co.label}?` : `Reject ${co.label}?`}
          description={
            deciding === 'approve'
              ? `${formatCurrency(co.amount, 2)} goes on the job's billing.`
              : 'Say why, so it can be corrected and sent again.'
          }
          footer={
            <>
              <Button variant="secondary" onClick={() => setDeciding(null)}>
                Cancel
              </Button>
              <Button isLoading={busy} onClick={decide}>
                {deciding === 'approve' ? 'Approve' : 'Reject'}
              </Button>
            </>
          }
        >
          <TextArea
            id="co-decision-note"
            label={deciding === 'approve' ? 'Note (optional)' : 'Reason'}
            rows={3}
            maxLength={1000}
            value={note}
            onChange={(event) => setNote(event.target.value)}
            {...(fieldErrors['note'] ? { error: fieldErrors['note'] } : {})}
          />
        </Modal>
      )}

      <ConfirmDialog
        isOpen={deleting}
        title="Delete this change order?"
        description={`${co.label} and its evidence will be removed.`}
        confirmLabel="Delete"
        confirmVariant="danger"
        tone="danger"
        onConfirm={() => router.delete(routeTo.changeOrder(co.id))}
        onCancel={() => setDeleting(false)}
      />
    </PageTransition>
  )
}

ChangeOrderShow.layout = appLayout
