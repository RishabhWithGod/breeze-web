import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Check, Pencil, Trash2 } from 'lucide-react'
import {
  Badge,
  Button,
  ConfirmDialog,
  IconButton,
  Modal,
  SectionHeading,
  SelectField,
  Table,
  TextInput,
} from '@/components/common'
import { routeTo } from '@/constants'
import type {
  FieldMaterialAdded,
  FieldMaterialPlanned,
  JobFieldMaterials,
  TableColumn,
} from '@/types'
import { formatCurrency, formatNumber, formatRelative } from '@/utils'

export interface JobFieldMaterialsPanelProps {
  jobId: number
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

function buildAddedColumns(
  canManage: boolean,
  onEdit: (row: FieldMaterialAdded) => void,
  onApprove: (row: FieldMaterialAdded) => void,
  onRemove: (row: FieldMaterialAdded) => void,
): readonly TableColumn<FieldMaterialAdded>[] {
  const columns: TableColumn<FieldMaterialAdded>[] = [
    {
      key: 'material',
      header: 'Item',
      render: (row) => (
        <span className="inline-flex flex-wrap items-center gap-2">
          <span className="font-medium text-white">{row.description}</span>
          <Badge tone={row.kind === 'labor' ? 'info' : 'neutral'} size="sm">
            {row.kind === 'labor' ? 'Labor' : 'Material'}
          </Badge>
        </span>
      ),
    },
    {
      key: 'task',
      header: 'Task',
      render: (row) => <span className="text-white/85">{row.taskTitle ?? '—'}</span>,
    },
    {
      key: 'qty',
      header: 'Qty / Hours',
      align: 'right',
      render: (row) => (row.kind === 'labor' ? `${formatNumber(row.qty)} hr` : qty(row.qty, row.unit)),
    },
    {
      key: 'price',
      header: 'Price / Rate',
      align: 'right',
      render: (row) =>
        formatCurrency(row.unitPrice, 2) + (row.kind === 'labor' ? ' /hr' : ''),
    },
    {
      key: 'total',
      header: 'Total',
      align: 'right',
      render: (row) => <span className="font-medium text-white">{formatCurrency(row.total, 2)}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) =>
        row.status === 'approved' ? (
          <Badge tone="success" size="sm">On estimate</Badge>
        ) : (
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

  if (canManage) {
    columns.push({
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) =>
        row.status === 'approved' ? null : (
          <span className="inline-flex items-center gap-1">
            <IconButton icon={Check} label={`Add ${row.description} to estimate`} size="sm" onClick={() => onApprove(row)} />
            <IconButton icon={Pencil} label={`Edit ${row.description}`} size="sm" onClick={() => onEdit(row)} />
            <IconButton
              icon={Trash2}
              label={`Remove ${row.description}`}
              size="sm"
              className="text-white/70 hover:text-status-danger"
              onClick={() => onRemove(row)}
            />
          </span>
        ),
    })
  }

  return columns
}

function EditEntryModal({
  jobId,
  row,
  onClose,
}: {
  jobId: number
  row: FieldMaterialAdded
  onClose: () => void
}) {
  const [kind, setKind] = useState<string>(row.kind)
  const [description, setDescription] = useState(row.description)
  const [quantity, setQuantity] = useState(String(row.qty))
  const [price, setPrice] = useState(String(row.unitPrice))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const total = (Number(quantity) || 0) * (Number(price) || 0)
  const labor = kind === 'labor'

  const save = () => {
    router.put(
      routeTo.jobFieldMaterial(jobId, row.id),
      { kind, description, quantity, unit_price: price },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onError: (e) => setErrors(e),
        onSuccess: onClose,
      },
    )
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Edit entry"
      footer={
        <div className="flex justify-end gap-3">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button isLoading={busy} onClick={save}>Save</Button>
        </div>
      }
    >
      <div className="space-y-4">
        <SelectField
          label="Type"
          value={kind}
          onChange={(e) => setKind(e.target.value)}
          options={[
            { value: 'material', label: 'Material' },
            { value: 'labor', label: 'Labor' },
          ]}
        />
        <TextInput
          label={labor ? 'Labor description' : 'Material'}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          {...(errors.description ? { error: errors.description } : {})}
        />
        <div className="grid grid-cols-2 gap-4">
          <TextInput
            label={labor ? 'Hours' : 'Quantity'}
            type="number"
            min="0"
            step="any"
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            {...(errors.quantity ? { error: errors.quantity } : {})}
          />
          <TextInput
            label={labor ? 'Rate per hour' : 'Price'}
            type="number"
            min="0"
            step="any"
            value={price}
            onChange={(e) => setPrice(e.target.value)}
            {...(errors.unit_price ? { error: errors.unit_price } : {})}
          />
        </div>
        <p className="text-right text-md text-white/85">
          Total <span className="font-semibold text-white">{formatCurrency(total, 2)}</span>
        </p>
      </div>
    </Modal>
  )
}

/**
 * What the crew reported from the mobile "Material and Work Changes" screen —
 * planned estimate lines against their actual quantity, and materials added on
 * site that were never planned. Read-only; these are not change orders.
 *
 * Over plan and added materials are billable exceptions for the office to
 * review; under plan is informational.
 */
export function JobFieldMaterialsPanel({ jobId, fieldMaterials }: JobFieldMaterialsPanelProps) {
  const { planned, added, needsReviewCount, addedTotal, canManage } = fieldMaterials
  const [editing, setEditing] = useState<FieldMaterialAdded | null>(null)
  const [removing, setRemoving] = useState<FieldMaterialAdded | null>(null)
  const addedColumns = buildAddedColumns(
    canManage,
    setEditing,
    (row) => router.post(routeTo.jobFieldMaterialApprove(jobId, row.id), {}, { preserveScroll: true }),
    setRemoving,
  )
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
          <div className="mb-2.5 flex flex-wrap items-center justify-between gap-2">
            <p className="text-2xs font-semibold tracking-wide text-white/70 uppercase">
              Added in the field
            </p>
            <p className="text-md text-white/85">
              Total <span className="font-semibold text-white">{formatCurrency(addedTotal, 2)}</span>
            </p>
          </div>
          <Table
            columns={addedColumns}
            rows={added}
            getRowId={(row) => row.id}
            dense
            caption="Materials added in the field"
          />
        </div>
      )}

      {editing && <EditEntryModal jobId={jobId} row={editing} onClose={() => setEditing(null)} />}

      <ConfirmDialog
        isOpen={removing !== null}
        title="Remove this entry?"
        description={removing?.description}
        confirmLabel="Remove"
        tone="danger"
        onCancel={() => setRemoving(null)}
        onConfirm={() => {
          if (removing) {
            router.delete(routeTo.jobFieldMaterial(jobId, removing.id), { preserveScroll: true })
          }
          setRemoving(null)
        }}
      />
    </div>
  )
}
