import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Check, Pencil, Plus, Trash2, X } from 'lucide-react'
import { Button, EmptyState, IconButton, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { InvoiceItemRow } from '@/types'
import { formatCurrency } from '@/utils'

/** Blank line used by the "add line" row. */
const EMPTY_DRAFT = {
  description: '',
  quantity: '1',
  unit_price: '0',
}

type Draft = typeof EMPTY_DRAFT

export interface InvoiceItemsTableProps {
  invoiceId: number
  items: readonly InvoiceItemRow[]
  /** Hidden once the invoice is no longer a draft/sent record. */
  editable: boolean
}

/**
 * The invoice's line items, editable in place. Every cell writes through to
 * the database, and the invoice's totals are recomputed server-side on each
 * change — nothing here computes a total the backend does not confirm.
 */
export function InvoiceItemsTable({ invoiceId, items, editable }: InvoiceItemsTableProps) {
  const [editingId, setEditingId] = useState<number | null>(null)
  const [draft, setDraft] = useState<Draft>(EMPTY_DRAFT)
  const [adding, setAdding] = useState(false)

  const startEdit = (item: InvoiceItemRow) => {
    setAdding(false)
    setEditingId(item.id)
    setDraft({
      description: item.description,
      quantity: String(item.quantity),
      unit_price: String(item.unitPrice),
    })
  }

  const payload = () => ({
    description: draft.description,
    quantity: Number(draft.quantity) || 0,
    unit_price: Number(draft.unit_price) || 0,
  })

  const saveEdit = (id: number) => {
    router.put(routeTo.invoiceItem(invoiceId, id), payload(), {
      preserveScroll: true,
      onSuccess: () => setEditingId(null),
    })
  }

  const create = () => {
    router.post(routeTo.invoiceItems(invoiceId), payload(), {
      preserveScroll: true,
      onSuccess: () => {
        setAdding(false)
        setDraft(EMPTY_DRAFT)
      },
    })
  }

  /* Labelled, like the estimate's own line editor — four bare boxes in a row
   * say nothing about which one takes the count and which takes the rate. */
  const editor = (
    <div className="grid gap-3 rounded-panel bg-white/8 p-3 sm:grid-cols-12">
      <TextInput
        id="item-description"
        label="Description"
        placeholder="e.g. 20A single-pole switch"
        className="sm:col-span-6"
        value={draft.description}
        onChange={(event) => setDraft({ ...draft, description: event.target.value })}
      />
      <TextInput
        id="item-quantity"
        label="Qty"
        className="sm:col-span-2"
        type="number"
        step="0.01"
        min={0}
        value={draft.quantity}
        onChange={(event) => setDraft({ ...draft, quantity: event.target.value })}
      />
      <TextInput
        id="item-unit-price"
        label="Unit price ($)"
        className="sm:col-span-2"
        type="number"
        step="0.01"
        min={0}
        value={draft.unit_price}
        onChange={(event) => setDraft({ ...draft, unit_price: event.target.value })}
      />
      <div className="sm:col-span-2">
        <p className="mb-2 text-md font-medium text-white">Line total</p>
        <p className="py-2.5 text-md font-semibold text-white">
          {formatCurrency((Number(draft.quantity) || 0) * (Number(draft.unit_price) || 0), 2)}
        </p>
      </div>
    </div>
  )

  return (
    <div className="flex flex-col gap-3">
      {items.length === 0 && !adding && (
        <EmptyState
          title="No line items yet"
          description={editable ? 'Add a line to start building this invoice.' : 'This invoice has no line items.'}
        />
      )}

      <ul className="flex flex-col gap-1.5">
        {items.map((item) => (
          <li key={item.id} className="rounded-panel bg-white/5 px-3 py-2.5">
            {editingId === item.id ? (
              <div className="flex flex-col gap-2">
                {editor}
                <div className="flex gap-2">
                  <Button size="sm" leftIcon={Check} onClick={() => saveEdit(item.id)}>
                    Save line
                  </Button>
                  <Button size="sm" variant="ghost" leftIcon={X} onClick={() => setEditingId(null)}>
                    Cancel
                  </Button>
                </div>
              </div>
            ) : (
              <div className="flex min-w-0 items-center gap-3">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-md text-white">{item.description}</p>
                  <p className="text-2xs text-white/75">
                    {item.quantity} × {formatCurrency(item.unitPrice, 2)}
                  </p>
                </div>
                <span className="shrink-0 text-md font-semibold text-white">
                  {formatCurrency(item.total, 2)}
                </span>
                {editable && (
                  <>
                    <IconButton icon={Pencil} label={`Edit ${item.description}`} size="sm" onClick={() => startEdit(item)} />
                    <IconButton
                      icon={Trash2}
                      label={`Remove ${item.description}`}
                      size="sm"
                      variant="danger"
                      onClick={() => router.delete(routeTo.invoiceItem(invoiceId, item.id), { preserveScroll: true })}
                    />
                  </>
                )}
              </div>
            )}
          </li>
        ))}
      </ul>

      {editable && (
        adding ? (
          <div className="flex flex-col gap-2">
            {editor}
            <div className="flex gap-2">
              <Button size="sm" leftIcon={Plus} onClick={create}>
                Add line
              </Button>
              <Button
                size="sm"
                variant="ghost"
                leftIcon={X}
                onClick={() => {
                  setAdding(false)
                  setDraft(EMPTY_DRAFT)
                }}
              >
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <Button
            variant="secondary"
            size="sm"
            leftIcon={Plus}
            className="self-start"
            onClick={() => {
              setEditingId(null)
              setDraft(EMPTY_DRAFT)
              setAdding(true)
            }}
          >
            Add Item
          </Button>
        )
      )}
    </div>
  )
}
