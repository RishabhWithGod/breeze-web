import { Head } from '@inertiajs/react'
import { ArrowLeft } from 'lucide-react'
import { Badge, ButtonLink, Card, CardHeader, IconBubble, Table } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { findElectricalTool } from '@/constants'
import { ROUTES } from '@/constants'
import type { TableColumn } from '@/types'

export interface ElectricalToolShowProps {
  tool: string
  projects: readonly { id: number; name: string }[]
}

interface StatRow {
  readonly label: string
  readonly value: string
}

interface ScheduleRow {
  readonly circuit: string
  readonly description: string
  readonly phase: string
  readonly color: string
  readonly load: string
}

interface ConversionRow {
  readonly quantity: string
  readonly input: string
  readonly output: string
}

/** Sample panel schedule — illustrative, not read from a real takeoff. */
const CIRCUIT_COLOR_ROWS: readonly ScheduleRow[] = [
  { circuit: '1', description: 'Lighting — North Wing', phase: 'A', color: 'Black', load: '1,440 VA' },
  { circuit: '2', description: 'Receptacles — Office 101', phase: 'B', color: 'Red', load: '1,800 VA' },
  { circuit: '3', description: 'HVAC — RTU-1', phase: 'C', color: 'Blue', load: '4,800 VA' },
  { circuit: '4', description: 'Lighting — South Wing', phase: 'A', color: 'Black', load: '1,200 VA' },
  { circuit: '5', description: 'Receptacles — Break Room', phase: 'B', color: 'Red', load: '1,500 VA' },
  { circuit: '6', description: 'Exterior Lighting', phase: 'C', color: 'Blue', load: '960 VA' },
]

const CONDUIT_FILL_STATS: readonly StatRow[] = [
  { label: 'Conduit Type', value: 'EMT' },
  { label: 'Trade Size', value: '1 in' },
  { label: 'Conductors', value: '4 × #12 AWG THHN' },
  { label: 'Allowable Fill (NEC Ch. 9, Table 1)', value: '40%' },
  { label: 'Calculated Fill', value: '28%' },
]

const VOLTAGE_DROP_STATS: readonly StatRow[] = [
  { label: 'Supply Voltage', value: '240 V, Single Phase' },
  { label: 'Circuit Length', value: '150 ft' },
  { label: 'Load Current', value: '40 A' },
  { label: 'Conductor', value: '#8 AWG, Copper' },
  { label: 'Calculated Voltage Drop', value: '2.1% (5.0 V)' },
]

const MEASUREMENT_ROWS: readonly ConversionRow[] = [
  { quantity: 'Length', input: '100 ft', output: '30.48 m' },
  { quantity: 'Area', input: '500 sq ft', output: '46.45 sq m' },
  { quantity: 'Volume', input: '10 cu ft', output: '0.283 cu m' },
  { quantity: 'Wire Pull', input: '250 ft', output: '76.2 m' },
]

function StatList({ rows }: { rows: readonly StatRow[] }) {
  return (
    <dl className="space-y-3">
      {rows.map((row) => (
        <div key={row.label} className="flex items-center justify-between gap-4 border-b border-hairline pb-3 last:border-0 last:pb-0">
          <dt className="text-md text-white/80">{row.label}</dt>
          <dd className="text-md font-semibold text-white">{row.value}</dd>
        </div>
      ))}
    </dl>
  )
}

/**
 * One tool's own preview screen — the content is a static, illustrative sample
 * calculation, not a live result. See `ElectricalTools.tsx` for the catalog.
 */
export default function ElectricalToolShow({ tool }: ElectricalToolShowProps) {
  const definition = findElectricalTool(tool)

  if (!definition) return null

  const circuitColumns: readonly TableColumn<ScheduleRow>[] = [
    { key: 'circuit', header: 'Circuit', render: (row) => row.circuit },
    { key: 'description', header: 'Description', render: (row) => row.description },
    { key: 'phase', header: 'Phase', render: (row) => row.phase },
    { key: 'color', header: 'Color', render: (row) => row.color },
    { key: 'load', header: 'Load', align: 'right', render: (row) => row.load },
  ]

  const conversionColumns: readonly TableColumn<ConversionRow>[] = [
    { key: 'quantity', header: 'Quantity', render: (row) => row.quantity },
    { key: 'input', header: 'Input', render: (row) => row.input },
    { key: 'output', header: 'Converted', align: 'right', render: (row) => row.output },
  ]

  return (
    <PageTransition>
      <Head title={definition.name} />

      <PageHeader
        breadcrumbs={[{ label: 'Electrical Tools', href: ROUTES.electricalTools }, { label: definition.name }]}
        title={
          <span className="flex flex-wrap items-center gap-3">
            {definition.name}
            <Badge tone="info" size="sm">
              Preview
            </Badge>
          </span>
        }
        subtitle={definition.description}
        actions={
          <ButtonLink href={ROUTES.electricalTools} variant="secondary" leftIcon={ArrowLeft}>
            Back to Electrical Tools
          </ButtonLink>
        }
      />

      <Card padding="lg">
        <CardHeader
          title="Sample Calculation"
          subtitle="An illustrative example — enter your own project's values once this tool is fully connected."
          actions={<IconBubble icon={definition.icon} tone="brand" />}
        />

        {tool === 'circuit-colors' && (
          <Table columns={circuitColumns} rows={CIRCUIT_COLOR_ROWS} getRowId={(row) => row.circuit} />
        )}
        {tool === 'conduit-fill' && <StatList rows={CONDUIT_FILL_STATS} />}
        {tool === 'voltage-drop' && <StatList rows={VOLTAGE_DROP_STATS} />}
        {tool === 'measurement-calculator' && (
          <Table columns={conversionColumns} rows={MEASUREMENT_ROWS} getRowId={(row) => row.quantity} />
        )}
      </Card>
    </PageTransition>
  )
}

ElectricalToolShow.layout = appLayout
