import { Badge, SectionHeading, Table } from '@/components/common'
import type {
  FieldMaterialAdded,
  FieldMaterialPlanned,
  JobFieldMaterials,
  TableColumn,
} from '@/types'
import { formatNumber, formatRelative } from '@/utils'

export interface JobFieldMaterialsPanelProps {
  fieldMaterials: JobFieldMaterials
}

const qty = (value: number, unit: string | null) =>
  `${formatNumber(value)}${unit ? ` ${unit}` : ''}`

const plannedColumns: readonly TableColumn<FieldMaterialPlanned>[] = [
  {
    key: 'material',
    header: 'Material',
    render: (row) => <span className="font-medium text-white">{row.description}</span>,
  },
  {
    key: 'task',
    header: 'Task',
    render: (row) => <span className="text-white/85">{row.taskTitle}</span>,
  },
  {
    key: 'planned',
    header: 'Planned',
    align: 'right',
    render: (row) => qty(row.plannedQty, row.unit),
  },
  {
    key: 'actual',
    header: 'Actual',
    align: 'right',
    render: (row) =>
      row.actualQty === null ? (
        <span className="text-white/60">Not reported</span>
      ) : (
        qty(row.actualQty, row.unit)
      ),
  },
  {
    key: 'variance',
    header: 'Variance',
    render: (row) => {
      if (row.actualQty === null) return <span className="text-white/60">—</span>
      const diff = row.actualQty - row.plannedQty
      if (row.exception === 'over') {
        return (
          <span className="inline-flex flex-wrap items-center gap-2">
            <Badge tone="warning" size="sm">Over plan</Badge>
            <span className="tabular-nums">+{formatNumber(diff)}</span>
            <Badge tone="danger" size="sm">Needs review</Badge>
          </span>
        )
      }
      if (row.exception === 'under') {
        return (
          <span className="inline-flex flex-wrap items-center gap-2">
            <Badge tone="info" size="sm">Under plan</Badge>
            <span className="tabular-nums">{formatNumber(diff)}</span>
          </span>
        )
      }
      return <span className="text-white/75">On plan</span>
    },
  },
  {
    key: 'reason',
    header: 'Reason',
    render: (row) => <span className="text-white/85">{row.reason ?? '—'}</span>,
  },
]

const addedColumns: readonly TableColumn<FieldMaterialAdded>[] = [
  {
    key: 'material',
    header: 'Material',
    render: (row) => <span className="font-medium text-white">{row.description}</span>,
  },
  {
    key: 'task',
    header: 'Task',
    render: (row) => <span className="text-white/85">{row.taskTitle ?? '—'}</span>,
  },
  {
    key: 'qty',
    header: 'Quantity',
    align: 'right',
    render: (row) => qty(row.qty, row.unit),
  },
  {
    key: 'status',
    header: 'Status',
    render: () => (
      <Badge tone="danger" size="sm">Needs review</Badge>
    ),
  },
  {
    key: 'reason',
    header: 'Reason',
    render: (row) => <span className="text-white/85">{row.reason ?? '—'}</span>,
  },
  {
    key: 'addedBy',
    header: 'Added by',
    render: (row) => (
      <span className="text-white/85">
        {row.addedBy ?? '—'} · {formatRelative(row.createdAt)}
      </span>
    ),
  },
]

/**
 * What the crew reported from the mobile "Material and Work Changes" screen —
 * planned estimate lines against their actual quantity, and materials added on
 * site that were never planned. Read-only; these are not change orders.
 *
 * Over plan and added materials are billable exceptions for the office to
 * review; under plan is informational.
 */
export function JobFieldMaterialsPanel({ fieldMaterials }: JobFieldMaterialsPanelProps) {
  const { planned, added, needsReviewCount } = fieldMaterials
  const reported = planned.filter((line) => line.actualQty !== null)

  if (reported.length === 0 && added.length === 0) return null

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <SectionHeading
          as="h3"
          title="Materials: planned vs actual"
          subtitle="Reported by the crew from the mobile app — separate from change orders"
        />
        {needsReviewCount > 0 ? (
          <Badge tone="danger">
            {needsReviewCount} {needsReviewCount === 1 ? 'item needs' : 'items need'} review
          </Badge>
        ) : (
          <Badge tone="success">Nothing to review</Badge>
        )}
      </div>

      {reported.length > 0 && (
        <Table
          columns={plannedColumns}
          rows={reported}
          getRowId={(row) => row.id}
          dense
          caption="Planned versus actual materials"
        />
      )}

      {added.length > 0 && (
        <div>
          <p className="mb-2.5 text-2xs font-semibold tracking-wide text-white/70 uppercase">
            Added in the field
          </p>
          <Table
            columns={addedColumns}
            rows={added}
            getRowId={(row) => row.id}
            dense
            caption="Materials added in the field"
          />
        </div>
      )}
    </div>
  )
}
