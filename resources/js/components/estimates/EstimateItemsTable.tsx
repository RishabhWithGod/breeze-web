import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Pencil, Trash2 } from 'lucide-react'
import { Badge, Button, EmptyState, IconButton } from '@/components/common'
import { ESTIMATE_CATEGORY_LABEL, routeTo } from '@/constants'
import type { EstimateItemRow, SelectOption } from '@/types'
import { saveInBackground, useAutosave } from '@/lib/autosave'
import { formatCurrency } from '@/utils'
import { EMPTY_LINE_DRAFT, type LineDraft } from './estimateLineDraft'
import { EstimateLineFields } from './EstimateLineFields'

/**
 * Where this line's rate came from.
 *
 * A price can come from this project's own uploaded rate list, from the
 * estimator's price book once the project's own list had nothing to say, or
 * from nowhere at all. A line neither has ever seen is priced at zero rather
 * than a guess, and flagged so it doesn't get sent out unread.
 */
function PricingBadge({ item }: { item: EstimateItemRow }) {
  if (item.pricingSource === 'unmatched') {
    return (
      <Badge tone="warning" size="sm" className="ml-2">
        Needs a rate
      </Badge>
    )
  }

  // A word match found this item by every word in its name appearing somewhere
  // in a description — "Panel" landing on one specific panelboard. Right often
  // enough to offer, not often enough to send out unread.
  if (
    (item.pricingSource === 'vendor-rate-list' || item.pricingSource === 'price-book') &&
    item.pricingConfidence === 'words'
  ) {
    return (
      <Badge tone="info" size="sm" className="ml-2">
        Check match
      </Badge>
    )
  }

  return null
}

export interface EstimateItemsTableProps {
  estimateId: number
  items: readonly EstimateItemRow[]
  categories: readonly SelectOption[]
  /** What a labor line defaults to the moment Labor is picked. */
  laborRate: number
}

/**
 * The estimate's line items, grouped by section and editable in place.
 *
 * Quantities and rates generated from the takeoff are only defaults — every cell
 * here writes through to the database, and the estimate's totals are recomputed
 * server-side on each change. Adding a new line lives in its own card, further
 * down the page next to the totals it changes — this one is for what is
 * already on the estimate.
 */
export function EstimateItemsTable({
  estimateId,
  items,
  categories,
  laborRate,
}: EstimateItemsTableProps) {
  const [editingId, setEditingId] = useState<number | null>(null)
  const [draft, setDraft] = useState<LineDraft>(EMPTY_LINE_DRAFT)

  const startEdit = (item: EstimateItemRow) => {
    setEditingId(item.id)
    setDraft({
      category: item.category,
      description: item.description,
      unit: item.unit,
      quantity: String(item.quantity),
      unit_cost: String(item.unitCost),
    })
  }

  const payloadFor = (draftValue: LineDraft) => ({
    category: draftValue.category,
    description: draftValue.description,
    unit: draftValue.unit,
    quantity: Number(draftValue.quantity) || 0,
    unit_cost: Number(draftValue.unit_cost) || 0,
  })

  const saveEdit = (id: number, keepOpen = false) => {
    router.put(routeTo.estimateItem(estimateId, id), payloadFor(draft), {
      preserveScroll: true,
      ...(keepOpen ? { preserveState: true } : { onSuccess: () => setEditingId(null) }),
    })
  }

  // What is typed into a line is saved as it is typed, not only when "Save line" is pressed — so
  // leaving this screen (Continue to Job, Back, anywhere) never drops it.
  const editing = editingId === null ? null : (items.find((item) => item.id === editingId) ?? null)
  const complete =
    draft.description.trim() !== '' &&
    Number.isFinite(Number(draft.quantity)) &&
    draft.quantity.trim() !== '' &&
    Number.isFinite(Number(draft.unit_cost)) &&
    draft.unit_cost.trim() !== ''
  const changed =
    editing !== null &&
    (draft.category !== editing.category ||
      draft.description !== editing.description ||
      draft.unit !== editing.unit ||
      Number(draft.quantity) !== editing.quantity ||
      Number(draft.unit_cost) !== editing.unitCost)

  useAutosave({
    enabled: editing !== null,
    dirty: changed && complete,
    signature: JSON.stringify(draft),
    save: () => editing && saveEdit(editing.id, true),
    flush: () =>
      editing ? saveInBackground(routeTo.estimateItem(estimateId, editing.id), payloadFor(draft)) : Promise.resolve(true),
  })

  const grouped = (['material', 'fixture', 'labor', 'equipment'] as const)
    .map((category) => ({
      category,
      label: ESTIMATE_CATEGORY_LABEL[category],
      rows: items.filter((item) => item.category === category),
    }))
    .filter((group) => group.rows.length > 0)

  return (
    <div className="flex flex-col gap-5">
      {grouped.length === 0 && (
        <EmptyState
          title="No line items yet"
          description="Add a line from the card below, or generate the estimate from a reviewed takeoff."
        />
      )}

      {grouped.map((group) => (
        <section key={group.category}>
          <div className="mb-2 flex items-center justify-between gap-3">
            <h4 className="text-md font-semibold text-white">{group.label}</h4>
            <span className="font-display text-lg font-bold text-brand-soft">
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
                    <EstimateLineFields
                      draft={draft}
                      onChange={setDraft}
                      categories={categories}
                      laborRate={laborRate}
                    />
                    <div className="flex gap-2">
                      {/* "Save line" is hidden: edits save themselves as they are typed (see useAutosave). */}
                      <Button
                        size="sm"
                        variant="ghost"
                        // Closing never loses what was just typed: anything still waiting is saved first.
                        onClick={() => (changed && complete ? saveEdit(item.id) : setEditingId(null))}
                      >
                        Done
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
                        <PricingBadge item={item} />
                      </p>
                      <p className="text-2xs text-white/75">
                        {/*
                          Four decimals where the rate has them: conduit is
                          priced at $0.8296 a foot, and showing it as $0.83
                          makes the line total look like an error.
                        */}
                        {item.quantity} {item.unit} @{' '}
                        {formatCurrency(item.unitCost, item.unitCost < 1 ? 4 : 2)}
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
    </div>
  )
}
