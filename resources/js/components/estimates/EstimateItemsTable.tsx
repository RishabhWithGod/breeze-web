import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Check, Pencil, Trash2, X } from 'lucide-react'
import { Badge, Button, EmptyState, IconButton } from '@/components/common'
import { ESTIMATE_CATEGORY_LABEL, routeTo } from '@/constants'
import type { EstimateItemRow, SelectOption } from '@/types'
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

  const saveEdit = (id: number) => {
    router.put(
      routeTo.estimateItem(estimateId, id),
      {
        category: draft.category,
        description: draft.description,
        unit: draft.unit,
        quantity: Number(draft.quantity) || 0,
        unit_cost: Number(draft.unit_cost) || 0,
      },
      {
        preserveScroll: true,
        onSuccess: () => setEditingId(null),
      },
    )
  }

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
            <span className="text-lg font-bold text-brand-soft">
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
