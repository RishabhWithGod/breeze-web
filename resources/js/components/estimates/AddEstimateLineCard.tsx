import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Plus } from 'lucide-react'
import { Button } from '@/components/common'
import { ESTIMATE_CATEGORY_LABEL, routeTo } from '@/constants'
import type { EstimateItemRow, SelectOption } from '@/types'
import { formatCurrency } from '@/utils'
import { EstimateLineFields } from './EstimateLineFields'
import { EMPTY_LINE_DRAFT, type LineDraft } from './estimateLineDraft'

export interface AddEstimateLineCardProps {
  estimateId: number
  items: readonly EstimateItemRow[]
  categories: readonly SelectOption[]
  /** What a labor line defaults to the moment Labor is picked. */
  laborRate: number
}

/**
 * Where a new line actually gets added — kept beside the totals it changes
 * rather than buried at the foot of a long list of existing ones.
 *
 * Below the form: every line already on the estimate, grouped the same way
 * the totals are, so it's obvious which section — Material, Labor, and so on
 * — a newly added line landed in without scrolling back up to the full list.
 * Editing stays up there too; this card only ever adds.
 */
export function AddEstimateLineCard({ estimateId, items, categories, laborRate }: AddEstimateLineCardProps) {
  const [draft, setDraft] = useState<LineDraft>(EMPTY_LINE_DRAFT)

  const create = () => {
    router.post(
      routeTo.estimateItems(estimateId),
      {
        category: draft.category,
        description: draft.description,
        unit: draft.unit,
        quantity: Number(draft.quantity) || 0,
        unit_cost: Number(draft.unit_cost) || 0,
      },
      {
        preserveScroll: true,
        onSuccess: () => setDraft(EMPTY_LINE_DRAFT),
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
      <div className="flex flex-col gap-2">
        <EstimateLineFields
          draft={draft}
          onChange={setDraft}
          categories={categories}
          laborRate={laborRate}
        />
        <Button size="sm" leftIcon={Plus} className="self-start" onClick={create}>
          Add Addendum
        </Button>
      </div>

      {grouped.length > 0 && (
        <div className="flex flex-col gap-4 border-t border-hairline pt-4">
          {grouped.map((group) => (
            <section key={group.category}>
              <div className="mb-1.5 flex items-center justify-between gap-3">
                <h4 className="text-sm font-semibold text-white/90">{group.label}</h4>
                <span className="text-sm text-white/70">
                  {formatCurrency(
                    group.rows.reduce((total, item) => total + item.total, 0),
                    2,
                  )}
                </span>
              </div>

              <ul className="flex flex-col gap-1">
                {group.rows.map((item) => (
                  <li
                    key={item.id}
                    className="flex min-w-0 items-center gap-3 rounded-panel bg-white/4 px-3 py-2"
                  >
                    <span className="min-w-0 flex-1 truncate text-sm text-white/85">
                      {item.description}
                    </span>
                    <span className="shrink-0 text-sm text-white/70">
                      {item.quantity} {item.unit}
                    </span>
                    <span className="shrink-0 text-sm font-medium text-white">
                      {formatCurrency(item.total, 2)}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
