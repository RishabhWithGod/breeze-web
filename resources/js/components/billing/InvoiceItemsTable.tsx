import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Pencil, Plus, Receipt, Trash2 } from 'lucide-react'
import { Button, EmptyState, IconButton, Modal, SelectField, Table, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { InvoiceItemRow, InvoiceLineCategory, TableColumn } from '@/types'
import { formatCurrency } from '@/utils'
import { LineTypePill } from './LineTypePill'
import { LINE_CATEGORY_OPTIONS } from './lineTypes'

interface Draft {
  description: string
  category: InvoiceLineCategory
  quantity: string
  unit_price: string
}

const EMPTY_DRAFT: Draft = { description: '', category: 'other', quantity: '1', unit_price: '0' }

export interface InvoiceItemsTableProps {
  invoiceId: number
  items: readonly InvoiceItemRow[]
  /** Hidden once the invoice is no longer a draft/sent record. */
  editable: boolean
  /** The estimate number, said in the Source column for lines copied off it. */
  estimateNumber?: string | null
}

/**
 * The invoice's lines: what each is for, where it came from, and what it comes
 * to. Every change writes through to the database, and the invoice's totals are
 * recomputed server-side each time — nothing here computes a total the backend
 * does not confirm.
 */
export function InvoiceItemsTable({ invoiceId, items, editable, estimateNumber }: InvoiceItemsTableProps) {
  // `null` key is a new line; a number is that line being edited.
  const [editing, setEditing] = useState<{ id: number | null; draft: Draft } | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const openNew = () => {
    setErrors({})
    setEditing({ id: null, draft: EMPTY_DRAFT })
  }

  const openEdit = (item: InvoiceItemRow) => {
    setErrors({})
    setEditing({
      id: item.id,
      draft: {
        description: item.description,
        category: (item.sourceCategory as InvoiceLineCategory | null) ?? 'other',
        quantity: String(item.quantity),
        unit_price: String(item.unitPrice),
      },
    })
  }

  const save = () => {
    if (!editing) return

    const { id, draft } = editing
    const payload = {
      description: draft.description,
      source_category: draft.category,
      quantity: Number(draft.quantity) || 0,
      unit_price: Number(draft.unit_price) || 0,
    }
    const options = {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onSuccess: () => setEditing(null),
      onError: (bag: Record<string, string>) => setErrors(bag),
    }

    if (id === null) router.post(routeTo.invoiceItems(invoiceId), payload, options)
    else router.put(routeTo.invoiceItem(invoiceId, id), payload, options)
  }

  const columns: TableColumn<InvoiceItemRow>[] = [
    {
      key: 'n',
      header: '#',
      width: 'w-12',
      render: (item) => <span className="text-white/75">{items.indexOf(item) + 1}</span>,
    },
    { key: 'description', header: 'Description', render: (item) => <span className="text-white">{item.description}</span> },
    { key: 'type', header: 'Type', render: (item) => <LineTypePill category={item.sourceCategory} /> },
    {
      key: 'source',
      header: 'Source',
      render: (item) => (
        <span className="text-white/90">
          {item.source === 'estimate' ? `Estimate${estimateNumber ? ` ${estimateNumber}` : ''}` : 'Manual'}
        </span>
      ),
    },
    {
      key: 'qty',
      header: 'Qty',
      render: (item) => <span className="tabular-nums text-white">{item.quantity.toFixed(2)}</span>,
    },
    {
      key: 'price',
      header: 'Unit Price',
      render: (item) => (
        <span className="whitespace-nowrap tabular-nums text-white">{formatCurrency(item.unitPrice, 2)}</span>
      ),
    },
    {
      key: 'amount',
      header: 'Amount',
      render: (item) => (
        <span className="whitespace-nowrap tabular-nums text-white">{formatCurrency(item.total, 2)}</span>
      ),
    },
    ...(editable
      ? [
          {
            key: 'actions',
            header: 'Actions',
            render: (item: InvoiceItemRow) => (
              <span className="flex items-center gap-1">
                <IconButton icon={Pencil} label={`Edit ${item.description}`} size="sm" variant="ghost" onClick={() => openEdit(item)} />
                <IconButton
                  icon={Trash2}
                  label={`Remove ${item.description}`}
                  size="sm"
                  variant="ghost"
                  onClick={() => router.delete(routeTo.invoiceItem(invoiceId, item.id), { preserveScroll: true })}
                />
              </span>
            ),
          },
        ]
      : []),
  ]

  const draft = editing?.draft
  const amount = draft ? Math.round((Number(draft.quantity) || 0) * (Number(draft.unit_price) || 0) * 100) / 100 : 0

  return (
    <div className="flex flex-col gap-3">
      {editable && (
        <div className="flex justify-end">
          <Button leftIcon={Plus} onClick={openNew}>
            Add Line Item
          </Button>
        </div>
      )}

      {items.length === 0 ? (
        <EmptyState
          icon={Receipt}
          title="No line items yet"
          description={editable ? 'Add a line to start building this invoice.' : 'This invoice has no line items.'}
        />
      ) : (
        <div className="overflow-x-auto">
          <Table
            dense
            variant="lined"
            headerVariant="plain"
            className="min-w-3xl text-sm [&_th]:text-sm [&_td]:text-sm"
            columns={columns}
            rows={items}
            getRowId={(item) => item.id}
            caption="Invoice line items"
          />
        </div>
      )}

      <Modal
        isOpen={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.id === null ? 'Add line item' : 'Edit line item'}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button isLoading={busy} disabled={!draft || draft.description.trim() === ''} onClick={save}>
              {editing?.id === null ? 'Add line' : 'Save line'}
            </Button>
          </>
        }
      >
        {editing && draft && (
          <div className="space-y-4">
            <TextInput
              id="item-description"
              label="Description *"
              placeholder="e.g. Site preparation"
              value={draft.description}
              onChange={(event) => setEditing({ ...editing, draft: { ...draft, description: event.target.value } })}
              {...(errors['description'] ? { error: errors['description'] } : {})}
              autoFocus
            />
            <SelectField
              id="item-type"
              label="Type"
              options={LINE_CATEGORY_OPTIONS}
              value={draft.category}
              onChange={(event) =>
                setEditing({ ...editing, draft: { ...draft, category: event.target.value as InvoiceLineCategory } })
              }
            />
            <div className="grid grid-cols-2 gap-4">
              <TextInput
                id="item-quantity"
                label="Quantity"
                type="number"
                inputMode="decimal"
                step="0.01"
                min={0}
                value={draft.quantity}
                onChange={(event) => setEditing({ ...editing, draft: { ...draft, quantity: event.target.value } })}
                {...(errors['quantity'] ? { error: errors['quantity'] } : {})}
              />
              <TextInput
                id="item-unit-price"
                label="Unit price"
                type="number"
                inputMode="decimal"
                step="0.01"
                min={0}
                value={draft.unit_price}
                onChange={(event) => setEditing({ ...editing, draft: { ...draft, unit_price: event.target.value } })}
                {...(errors['unit_price'] ? { error: errors['unit_price'] } : {})}
              />
            </div>
            <p className="text-right text-sm text-white/80">
              Amount <strong className="text-white">{formatCurrency(amount, 2)}</strong>
            </p>
          </div>
        )}
      </Modal>
    </div>
  )
}
