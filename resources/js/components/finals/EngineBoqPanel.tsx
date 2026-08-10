import { Badge, Card, EmptyState, SectionHeading, Table } from '@/components/common'
import type { EngineBoqLine, TableColumn } from '@/types'
import { formatCurrency } from '@/utils'

export interface EngineBoqPanelProps {
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
export function EngineBoqPanel({ lines, subtotal, currency = 'USD' }: EngineBoqPanelProps) {
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
          <span className="text-2xs text-white/70">engine quantity</span>
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

  return (
    <Card padding="lg">
      <SectionHeading
        as="h3"
        title="AI bill of quantities"
        subtitle={
          subtotal
            ? `${lines.length} priced lines · ${currency} ${formatCurrency(subtotal, 2).replace('$', '')} before review`
            : `${lines.length} priced lines returned by the engine`
        }
      />
      <Table
        columns={columns}
        rows={lines}
        getRowId={(row, index) => `${row.item}-${index}`}
        variant="lined"
        dense
        caption="The AI engine's priced bill of quantities"
        emptyState={
          <EmptyState
            title="The engine priced nothing"
            description="No bill of quantities came back for this drawing, so the estimate falls back to the configured price book."
          />
        }
      />
    </Card>
  )
}
