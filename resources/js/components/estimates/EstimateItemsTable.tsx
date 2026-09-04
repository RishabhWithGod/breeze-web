import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Check, Pencil, Plus, Trash2, X } from 'lucide-react'
import {
  Badge,
  Button,
  EmptyState,
  IconButton,
  SelectField,
  TextInput,
} from '@/components/common'
import { ESTIMATE_CATEGORY_LABEL, routeTo } from '@/constants'
import type { EstimateCategory, EstimateItemRow, SelectOption } from '@/types'
import { formatCurrency } from '@/utils'

/** Blank line used by the "add line" row. */
const EMPTY_DRAFT = {
  category: 'material' as EstimateCategory,
  description: '',
  unit: 'ea',
  quantity: '1',
  unit_cost: '0',
}

type Draft = typeof EMPTY_DRAFT

export interface EstimateItemsTableProps {
  estimateId: number
  items: readonly EstimateItemRow[]
  categories: readonly SelectOption[]
}

/**
 * The estimate's line items, grouped by section and editable in place.
 *
 * Quantities and rates generated from the takeoff are only defaults — every cell
 * here writes through to the database, and the estimate's totals are recomputed
 * server-side on each change.
 */
export function EstimateItemsTable({
  estimateId,
  items,
  categories,
}: EstimateItemsTableProps) {
  const [editingId, setEditingId] = useState<number | null>(null)
  const [draft, setDraft] = useState<Draft>(EMPTY_DRAFT)
  const [adding, setAdding] = useState(false)

  const startEdit = (item: EstimateItemRow) => {
    setAdding(false)
    setEditingId(item.id)
    setDraft({
      category: item.category,
      description: item.description,
      unit: item.unit,
      quantity: String(item.quantity),
      unit_cost: String(item.unitCost),
    })
  }

  const payload = () => ({
    category: draft.category,
    description: draft.description,
    unit: draft.unit,
    quantity: Number(draft.quantity) || 0,
    unit_cost: Number(draft.unit_cost) || 0,
  })

  const saveEdit = (id: number) => {
    router.put(routeTo.estimateItem(estimateId, id), payload(), {
      preserveScroll: true,
      onSuccess: () => setEditingId(null),
    })
  }

  const create = () => {
    router.post(routeTo.estimateItems(estimateId), payload(), {
      preserveScroll: true,
      onSuccess: () => {
        setAdding(false)
        setDraft(EMPTY_DRAFT)
      },
    })
  }

  const grouped = (['material', 'fixture', 'labor', 'equipment'] as const)
    .map((category) => ({
      category,
      label: ESTIMATE_CATEGORY_LABEL[category],
      rows: items.filter((item) => item.category === category),
    }))
    .filter((group) => group.rows.length > 0)

  /*
   * The line being added or corrected. Every box is labelled: five unlabelled
   * cells in a row say nothing about which one takes the rate and which takes
   * the count, and a wrong guess is priced work.
   */
  const editor = (
    <div className="grid gap-3 rounded-panel bg-white/8 p-3 sm:grid-cols-12">
      <SelectField
        id="item-category"
        label="Category"
        className="sm:col-span-2"
        options={categories}
        value={draft.category}
        onChange={(event) =>
          setDraft({ ...draft, category: event.target.value as EstimateCategory })
        }
      />
      <TextInput
        id="item-description"
        label="Description"
        placeholder="e.g. 20A single-pole switch"
        className="sm:col-span-5"
        value={draft.description}
        onChange={(event) => setDraft({ ...draft, description: event.target.value })}
      />
      <TextInput
        id="item-unit"
        label="Unit"
        placeholder="ea"
        className="sm:col-span-1"
        value={draft.unit}
        onChange={(event) => setDraft({ ...draft, unit: event.target.value })}
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
        id="item-unit-cost"
        label="Unit cost ($)"
        className="sm:col-span-2"
        type="number"
        step="0.01"
        min={0}
        value={draft.unit_cost}
        onChange={(event) => setDraft({ ...draft, unit_cost: event.target.value })}
      />
    </div>
  )

  return (
    <div className="flex flex-col gap-5">
      {grouped.length === 0 && (
        <EmptyState
          title="No line items yet"
          description="Add a line, or generate the estimate from a reviewed takeoff."
        />
      )}

      {grouped.map((group) => (
        <section key={group.category}>
          <div className="mb-2 flex items-center justify-between gap-3">
            <h4 className="text-md font-semibold text-white">{group.label}</h4>
            <span className="text-sm text-white/85">
              {formatCurrency(
                group.rows.reduce((total, item) => total + item.total, 0),
                2,
              )}
            </span>
          </div>

          <ul className="flex flex-col gap-1.5">
            {group.rows.map((item) => (
              <li key={item.id} className="rounded-panel bg-white/5 px-3 py-2.5">
                {editingId === item.id ? (
                  <div className="flex flex-col gap-2">
                    {editor}
                    <div className="flex gap-2">
                      <Button size="sm" leftIcon={Check} onClick={() => saveEdit(item.id)}>
                        Save line
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        leftIcon={X}
                        onClick={() => setEditingId(null)}
                      >
                        Cancel
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div className="flex min-w-0 items-center gap-3">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-md text-white">
                        {item.description}
                        {item.source === 'manual' && (
                          <Badge tone="info" size="sm" className="ml-2">
                            Manual
                          </Badge>
                        )}
                      </p>
                      <p className="text-2xs text-white/75">
                        {item.quantity} {item.unit} @ {formatCurrency(item.unitCost, 2)}
                      </p>
                    </div>
                    <span className="shrink-0 text-md font-semibold text-white">
                      {formatCurrency(item.total, 2)}
                    </span>
                    <IconButton
                      icon={Pencil}
                      label={`Edit ${item.description}`}
                      size="sm"
                      onClick={() => startEdit(item)}
                    />
                    <IconButton
                      icon={Trash2}
                      label={`Remove ${item.description}`}
                      size="sm"
                      variant="danger"
                      onClick={() =>
                        router.delete(routeTo.estimateItem(estimateId, item.id), {
                          preserveScroll: true,
                        })
                      }
                    />
                  </div>
                )}
              </li>
            ))}
          </ul>
        </section>
      ))}

      {adding ? (
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
          Add a line
        </Button>
      )}
    </div>
  )
}
