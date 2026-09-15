import { SelectField, TextInput } from '@/components/common'
import type { EstimateCategory, SelectOption } from '@/types'
import { formatCurrency } from '@/utils'
import type { LineDraft } from './estimateLineDraft'

export interface EstimateLineFieldsProps {
  draft: LineDraft
  onChange: (draft: LineDraft) => void
  categories: readonly SelectOption[]
  /** What a labor line defaults to the moment Labor is picked. */
  laborRate: number
}

/**
 * The fields for one estimate line — added or corrected, the same five boxes
 * either way. Every one is labelled: five unlabelled cells in a row say
 * nothing about which one takes the rate and which takes the count, and a
 * wrong guess is priced work.
 *
 * Picking Labor fills the hourly rate in rather than leaving it at zero —
 * $50 an hour is the number almost every labor line actually is, and typing
 * it fresh each time is the kind of thing that gets forgotten. The total
 * below updates as either number changes, so the line's cost is never a
 * surprise on save.
 */
export function EstimateLineFields({ draft, onChange, categories, laborRate }: EstimateLineFieldsProps) {
  const total = (Number(draft.quantity) || 0) * (Number(draft.unit_cost) || 0)

  return (
    <div className="rounded-panel bg-white/8 p-3">
      <div className="grid gap-3 sm:grid-cols-12">
        <SelectField
          id="item-category"
          label="Category"
          className="sm:col-span-2"
          options={categories}
          value={draft.category}
          onChange={(event) => {
            const category = event.target.value as EstimateCategory

            onChange(
              category === 'labor'
                ? { ...draft, category, unit: 'hr', unit_cost: String(laborRate) }
                : { ...draft, category },
            )
          }}
        />
        <TextInput
          id="item-description"
          label="Description"
          placeholder="e.g. 20A single-pole switch"
          className="sm:col-span-5"
          value={draft.description}
          onChange={(event) => onChange({ ...draft, description: event.target.value })}
        />
        <TextInput
          id="item-unit"
          label="Unit"
          placeholder="ea"
          className="sm:col-span-1"
          value={draft.unit}
          onChange={(event) => onChange({ ...draft, unit: event.target.value })}
        />
        <TextInput
          id="item-quantity"
          label={draft.category === 'labor' ? 'Hours' : 'Qty'}
          className="sm:col-span-2"
          type="number"
          step="0.01"
          min={0}
          value={draft.quantity}
          onChange={(event) => onChange({ ...draft, quantity: event.target.value })}
        />
        <TextInput
          id="item-unit-cost"
          label={draft.category === 'labor' ? 'Rate ($/hr)' : 'Unit cost ($)'}
          className="sm:col-span-2"
          type="number"
          step="0.01"
          min={0}
          value={draft.unit_cost}
          onChange={(event) => onChange({ ...draft, unit_cost: event.target.value })}
        />
      </div>

      <div className="mt-3 flex items-center justify-between gap-3 border-t border-hairline pt-3">
        <span className="text-sm text-white/70">
          {draft.quantity || 0} {draft.unit || 'ea'} @ {formatCurrency(Number(draft.unit_cost) || 0, 2)}
        </span>
        <span className="text-md font-semibold text-white">
          Total: {formatCurrency(total, 2)}
        </span>
      </div>
    </div>
  )
}
