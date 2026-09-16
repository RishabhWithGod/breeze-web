import type { ReactNode } from 'react'
import { Badge, Card, EmptyState, SectionHeading, Table } from '@/components/common'
import type {
  CircuitRow,
  EquipmentRow,
  PanelScheduleRow,
  TableColumn,
  WireSizeRow,
} from '@/types'

interface FrameProps {
  bare: boolean
  title: string
  subtitle: string
  children: ReactNode
}

/**
 * The card and heading each panel draws around itself — unless the caller has
 * already drawn both, as a collapsible section does.
 */
function Frame({ bare, title, subtitle, children }: FrameProps) {
  if (bare) return <>{children}</>

  return (
    <Card padding="lg">
      <SectionHeading as="h3" title={title} subtitle={subtitle} />
      {children}
    </Card>
  )
}

export interface WireSizesPanelProps {
  /** Drop the card and heading — a caller that already has both. */
  bare?: boolean
  wireSizes: readonly WireSizeRow[]
}

/**
 * Conductor sizes the engine read off the drawing.
 *
 * Its own section because wire is priced by length, not by symbol count — these
 * rows are evidence for the estimator, not part of the device takeoff.
 */
export function WireSizesPanel({ wireSizes, bare = false }: WireSizesPanelProps) {
  const columns: readonly TableColumn<WireSizeRow>[] = [
    {
      key: 'size',
      header: 'Size',
      render: (row) => <span className="font-medium text-white">{row.size}</span>,
    },
    {
      key: 'count',
      header: 'Count',
      align: 'right',
      width: 'w-32',
      render: (row) => row.count,
    },
  ]

  return (
    <Frame bare={bare} title="Wire sizes" subtitle={`${wireSizes.length} conductor ${wireSizes.length === 1 ? 'size' : 'sizes'} found on the drawing`}>
      <Table
        columns={columns}
        rows={wireSizes}
        getRowId={(row, index) => `${row.size}-${row.page}-${index}`}
        variant="lined"
        dense
        caption="Conductor sizes read off the drawing"
        emptyState={
          <EmptyState
            title="No wire sizes found"
            description="The engine did not read any conductor sizes from this drawing."
          />
        }
      />
    </Frame>
  )
}

export interface PanelSchedulesPanelProps {
  /** Drop the card and heading — a caller that already has both. */
  bare?: boolean
  schedules: readonly PanelScheduleRow[]
}

/** Panel schedule tables the engine lifted off the drawing, as read. */
export function PanelSchedulesPanel({ schedules, bare = false }: PanelSchedulesPanelProps) {
  return (
    <Frame bare={bare} title="Panel schedules" subtitle={`${schedules.length} ${schedules.length === 1 ? 'schedule' : 'schedules'} extracted`}>

      {schedules.length === 0 ? (
        <EmptyState
          title="No panel schedules found"
          description="The engine found no schedule tables on this drawing."
        />
      ) : (
        <ul className="flex flex-col gap-4">
          {schedules.map((schedule, index) => (
            <li key={`${schedule.panelName}-${schedule.page}-${index}`}>
              <div className="mb-2 flex flex-wrap items-center gap-2">
                <span className="text-md font-semibold text-white">
                  {schedule.panelName || 'Unnamed panel'}
                </span>
                <Badge tone="neutral" size="sm">
                  page {schedule.page}
                </Badge>
                <Badge tone="neutral" size="sm">
                  {schedule.rows.length} rows
                </Badge>
              </div>

              {schedule.rawHeaders.length > 0 && (
                <p className="mb-2 font-mono text-2xs text-white/70">
                  {schedule.rawHeaders.join(' · ')}
                </p>
              )}

              {/* Rows are whatever the table held, so they are rendered generically. */}
              <div className="overflow-x-auto">
                <ul className="flex min-w-0 flex-col gap-1">
                  {schedule.rows.slice(0, 12).map((row, rowIndex) => (
                    <li
                      key={rowIndex}
                      className="rounded-panel bg-white/5 px-3 py-2 font-mono text-2xs text-white"
                    >
                      {Object.entries(row)
                        .map(([key, value]) => `${key}: ${String(value ?? '')}`)
                        .join('  ·  ')}
                    </li>
                  ))}
                </ul>
              </div>

              {schedule.rows.length > 12 && (
                <p className="mt-2 text-2xs text-white/70">
                  {schedule.rows.length - 12} more rows in the stored response.
                </p>
              )}
            </li>
          ))}
        </ul>
      )}
    </Frame>
  )
}

export interface EquipmentPanelProps {
  /** Drop the card and heading — a caller that already has both. */
  bare?: boolean
  equipment: readonly EquipmentRow[]
}

/** Equipment schedule rows the engine extracted. */
export function EquipmentPanel({ equipment, bare = false }: EquipmentPanelProps) {
  const columns: readonly TableColumn<EquipmentRow>[] = [
    {
      key: 'tag',
      header: 'Tag',
      width: 'w-28',
      render: (row) => <span className="font-medium text-white">{row.tag || '—'}</span>,
    },
    { key: 'description', header: 'Description', render: (row) => row.description || '—' },
    { key: 'rating', header: 'Rating', width: 'w-32', render: (row) => row.rating || '—' },
    {
      key: 'quantity',
      header: 'Qty',
      align: 'right',
      width: 'w-20',
      render: (row) => row.quantity,
    },
    { key: 'page', header: 'Page', align: 'right', width: 'w-20', render: (row) => row.page },
  ]

  return (
    <Frame bare={bare} title="Equipment" subtitle={`${equipment.length} ${equipment.length === 1 ? 'item' : 'items'} extracted`}>
      <Table
        columns={columns}
        rows={equipment}
        getRowId={(row, index) => `${row.tag}-${index}`}
        variant="lined"
        dense
        caption="Equipment schedule rows"
        emptyState={
          <EmptyState
            title="No equipment schedule found"
            description="The engine found no equipment rows on this drawing."
          />
        }
      />
    </Frame>
  )
}

export interface CircuitsPanelProps {
  /** Drop the card and heading — a caller that already has both. */
  bare?: boolean
  circuits: readonly CircuitRow[]
}

/** Circuits the engine read from schedules or homerun notes. */
export function CircuitsPanel({ circuits, bare = false }: CircuitsPanelProps) {
  const columns: readonly TableColumn<CircuitRow>[] = [
    {
      key: 'number',
      header: 'Circuit',
      width: 'w-24',
      render: (row) => <span className="font-medium text-white">{row.number}</span>,
    },
    { key: 'panel', header: 'Panel', width: 'w-28', render: (row) => row.panel || '—' },
    { key: 'breaker', header: 'Breaker', width: 'w-28', render: (row) => row.breaker || '—' },
    { key: 'description', header: 'Description', render: (row) => row.description || '—' },
    { key: 'page', header: 'Page', align: 'right', width: 'w-20', render: (row) => row.page },
  ]

  return (
    <Frame bare={bare} title="Circuits" subtitle={`${circuits.length} ${circuits.length === 1 ? 'circuit' : 'circuits'} extracted`}>
      <Table
        columns={columns}
        rows={circuits}
        getRowId={(row, index) => `${row.number}-${index}`}
        variant="lined"
        dense
        caption="Circuits read off the drawing"
        emptyState={
          <EmptyState
            title="No circuits found"
            description="The engine read no circuit information from this drawing."
          />
        }
      />
    </Frame>
  )
}
