import { Checkbox, StatusChip } from '@/components/common'
import type { EstimateItemRow, EstimateStatus } from '@/types'
import { cn, ESTIMATE_STATUS_LABEL, ESTIMATE_STATUS_TONE, formatCurrency } from '@/utils'

export interface AddendumSummary {
  readonly id: number
  readonly number: string
  readonly addendumNumber: number | null
  readonly addendumName: string | null
  readonly amount: number
  readonly status: string
  /** This addendum's own line items — only sent by the Estimate screen's pre-job workspace. */
  readonly items?: readonly EstimateItemRow[]
}

export interface AddendumSelectionListProps {
  original: { readonly id: number; readonly number: string; readonly amount: number; readonly status: string }
  addenda: readonly AddendumSummary[]
  /** Ids of the original and/or addenda currently checked. Nothing is forced on. */
  selected: ReadonlySet<number>
  onToggle: (id: number) => void
  /** Hides the checkboxes for a read-only listing — the original's own screen, before any selection matters. */
  selectable?: boolean
}

/**
 * The original estimate plus its addenda, each with its own total and
 * status, and — when `selectable` — a checkbox deciding whether it counts
 * toward the combined total below. The original is checkable and
 * uncheckable exactly like any addendum here — it is not forced into every
 * selection. Nothing here changes an estimate's own total; "Selected Total"
 * is only ever a preview of what a job raised from this selection would add
 * up to.
 */
export function AddendumSelectionList({
  original,
  addenda,
  selected,
  onToggle,
  selectable = true,
}: AddendumSelectionListProps) {
  const selectedTotal =
    (selected.has(original.id) ? original.amount : 0) +
    addenda.filter((a) => selected.has(a.id)).reduce((sum, a) => sum + a.amount, 0)

  return (
    <div className="flex flex-col gap-3">
      <Row
        label={`Original Estimate — ${original.number}`}
        amount={original.amount}
        status={original.status}
        checked={selected.has(original.id)}
        onChange={() => onToggle(original.id)}
        selectable={selectable}
      />

      {addenda.map((addendum) => (
        <Row
          key={addendum.id}
          label={addendum.addendumName || `Addendum ${addendum.addendumNumber ?? ''}`.trim()}
          sublabel={addendum.number}
          amount={addendum.amount}
          status={addendum.status}
          checked={selected.has(addendum.id)}
          onChange={() => onToggle(addendum.id)}
          selectable={selectable}
        />
      ))}

      {addenda.length === 0 && (
        <p className="text-sm text-white/70">No addenda yet — upload one to add scope to this estimate.</p>
      )}

      {selectable && (
        <div className="mt-1 flex items-center justify-between border-t border-hairline pt-3">
          <p className="text-md font-semibold text-white">Selected Total</p>
          <p className="text-lg font-bold tabular-nums text-white">{formatCurrency(selectedTotal, 2)}</p>
        </div>
      )}
    </div>
  )
}

function Row({
  label,
  sublabel,
  amount,
  status,
  checked,
  disabled,
  onChange,
  selectable,
}: {
  label: string
  sublabel?: string
  amount: number
  status: string
  checked: boolean
  disabled?: boolean
  onChange?: () => void
  selectable: boolean
}) {
  return (
    <div
      className={cn(
        'flex flex-wrap items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 p-3.5',
      )}
    >
      <div className="flex min-w-0 items-center gap-3">
        {selectable && (
          <Checkbox
            id={`addendum-${label}-${sublabel ?? 'original'}`}
            label=""
            checked={checked}
            disabled={disabled}
            onChange={onChange}
          />
        )}
        <div className="min-w-0">
          <p className="truncate text-md font-medium text-white">{label}</p>
          {sublabel && <p className="truncate text-sm text-white/70">{sublabel}</p>}
        </div>
      </div>

      <div className="flex items-center gap-3">
        <StatusChip
          tone={ESTIMATE_STATUS_TONE[status as EstimateStatus] ?? 'neutral'}
          label={ESTIMATE_STATUS_LABEL[status as EstimateStatus] ?? status}
        />
        <span className="tabular-nums font-semibold text-white">{formatCurrency(amount, 2)}</span>
      </div>
    </div>
  )
}
