import { Head } from '@inertiajs/react'
import { ArrowLeft } from 'lucide-react'
import { ButtonLink, Card, SectionHeading, Table } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { TableColumn } from '@/types'
import { formatCurrency } from '@/utils'

interface SourceLine {
  readonly id: number
  readonly project: string | null
  readonly section: string | null
  readonly subsection: string | null
  readonly description: string
  readonly quantity: number | null
  readonly unit: string | null
  readonly unitMaterialCost: number | null
  readonly unitManhours: number | null
  readonly totalCost: number | null
  /** The row it was read from, so the figure can be found in the workbook. */
  readonly sourceRow: number | null
}

export interface PriceBookItemProps {
  item: {
    readonly id: number
    readonly description: string
    readonly unit: string
    readonly section: string | null
    readonly subsection: string | null
    readonly unitMaterialCost: number | null
    readonly unitManhours: number | null
    readonly sampleCount: number
    readonly isPinned: boolean
  }
  lines: readonly SourceLine[]
}

/**
 * One rate, and every line it was worked out from.
 *
 * The point of the screen: a quoted figure that cannot be traced is a figure
 * nobody will defend to a client. Here the jobs are named, so a rate that looks
 * wrong can be argued with by opening the workbook it came from.
 */
export default function PriceBookItem({ item, lines }: PriceBookItemProps) {
  const columns: readonly TableColumn<SourceLine>[] = [
    {
      key: 'project',
      header: 'Priced on',
      render: (row) => (
        <div className="min-w-0">
          <span className="block truncate text-md text-white">{row.project ?? 'Unknown job'}</span>
          {(row.section || row.subsection) && (
            <span className="block truncate text-2xs text-white/70">
              {[row.section, row.subsection].filter(Boolean).join(' → ')}
              {row.sourceRow !== null && ` · row ${row.sourceRow}`}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'quantity',
      header: 'Qty',
      align: 'right',
      width: 'w-28',
      render: (row) => <span className="tabular-nums">{row.quantity ?? '—'}</span>,
    },
    {
      key: 'material',
      header: 'Material / unit',
      align: 'right',
      width: 'w-36',
      render: (row) => (
        <span className="tabular-nums">
          {row.unitMaterialCost === null ? '—' : `$${row.unitMaterialCost}`}
        </span>
      ),
    },
    {
      key: 'manhours',
      header: 'Manhours / unit',
      align: 'right',
      width: 'w-36',
      render: (row) => (
        <span className="tabular-nums">{row.unitManhours ?? '—'}</span>
      ),
    },
    {
      key: 'total',
      header: 'Line total',
      align: 'right',
      width: 'w-32',
      render: (row) => (
        <span className="tabular-nums">
          {row.totalCost === null ? '—' : formatCurrency(row.totalCost, 2)}
        </span>
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title={item.description} />

      <PageHeader
        title={item.description}
        subtitle={[item.section, item.subsection].filter(Boolean).join(' → ') || undefined}
        breadcrumbs={[
          { label: 'Price Book', href: ROUTES.priceBook },
          { label: 'Item' },
        ]}
        actions={
          <ButtonLink href={ROUTES.priceBook} variant="secondary" leftIcon={ArrowLeft}>
            Back to price book
          </ButtonLink>
        }
      />

      <div className="mb-6 grid gap-6 sm:grid-cols-3">
        <Card accent="brand" padding="lg">
          <p className="text-xs tracking-wide text-white/70 uppercase">Material per {item.unit}</p>
          <p className="mt-2 text-2xl font-bold tabular-nums text-white">
            {item.unitMaterialCost === null ? '—' : `$${item.unitMaterialCost}`}
          </p>
        </Card>
        <Card accent="warning" padding="lg">
          <p className="text-xs tracking-wide text-white/70 uppercase">Manhours per {item.unit}</p>
          <p className="mt-2 text-2xl font-bold tabular-nums text-white">
            {item.unitManhours ?? '—'}
          </p>
        </Card>
        <Card accent="success" padding="lg">
          <p className="text-xs tracking-wide text-white/70 uppercase">Priced on</p>
          <p className="mt-2 text-2xl font-bold tabular-nums text-white">
            {item.sampleCount} {item.sampleCount === 1 ? 'line' : 'lines'}
          </p>
        </Card>
      </div>

      <Card accent="neutral" padding="lg">
        <SectionHeading
          as="h3"
          title="Every line behind this rate"
          subtitle="Exactly as the estimator priced it — never edited by the import"
        />
        <Table columns={columns} rows={lines} getRowId={(row) => row.id} />
      </Card>
    </PageTransition>
  )
}

PriceBookItem.layout = appLayout
