import { Badge, Card, EmptyState, SectionHeading, Table } from '@/components/common'
import type { EngineBoqLine, TableColumn } from '@/types'
import { formatCurrency } from '@/utils'

export interface EngineBoqPanelProps {
  /**
   * Drop the card and heading — for a caller that has already drawn both, as a
   * collapsible section does. The same switch `WireSizesPanel` carries.
   */
  bare?: boolean
  lines: readonly EngineBoqLine[]
  /** The engine's own totals, before review. */
  subtotal?: number
  currency?: string
}

/**
 * The AI engine's priced bill of quantities, exactly as returned.
 *
 * This is what the estimate is built from. Lines flagged with a symbol were matched
 * to a reviewed count, so the estimate follows the review rather than the engine's
 * original quantity.
 */
export function EngineBoqPanel({ bare = false, lines, subtotal, currency = 'USD' }: EngineBoqPanelProps) {
  const columns: readonly TableColumn<EngineBoqLine>[] = [
    {
      key: 'item',
      header: 'Item',
      render: (row) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-white">{row.item}</p>
          {row.description && (
            <p className="truncate text-2xs text-white/75">{row.description}</p>
          )}
        </div>
      ),
    },
    {
      key: 'matched',
      header: 'Reviewed symbol',
      render: (row) =>
        row.matchedSymbol ? (
          <Badge tone="success" size="sm">
            {row.matchedSymbol}
          </Badge>
        ) : (
          <span className="text-2xs text-white/70">automatic quantity</span>
        ),
    },
    {
      key: 'quantity',
      header: 'Qty',
      align: 'right',
      width: 'w-24',
      render: (row) => `${row.quantity} ${row.unit}`,
    },
    {
      key: 'unitPrice',
      header: 'Unit price',
      align: 'right',
      width: 'w-28',
      render: (row) => formatCurrency(row.unitPrice, 2),
    },
    {
      key: 'subtotal',
      header: 'Subtotal',
      align: 'right',
      width: 'w-32',
      render: (row) => (
        <span className="font-semibold text-white">{formatCurrency(row.subtotal, 2)}</span>
      ),
    },
  ]

  const table = (
    <>
      <Table
        columns={columns}
        rows={lines}
        getRowId={(row, index) => `${row.item}-${index}`}
        variant="lined"
        dense
        caption="The automatic bill of quantities, before review"
        emptyState={
          <EmptyState
            title="Nothing was priced automatically"
            description="No bill of quantities came back for this drawing, so the estimate falls back to the configured price book."
          />
        }
      />
    </>
  )

  if (bare) return table

  return (
    <Card padding="lg">
      <SectionHeading
        as="h3"
        title="Automatic bill of quantities"
        subtitle={
          subtotal
            ? `${lines.length} priced lines · ${currency} ${formatCurrency(subtotal, 2).replace('$', '')} before review`
            : `${lines.length} priced lines, before review`
        }
      />
      {table}
    </Card>
  )
}
