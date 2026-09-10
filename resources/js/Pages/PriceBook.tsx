import { useCallback, useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { Pin, SearchX } from 'lucide-react'
import {
  Badge,
  Card,
  EmptyState,
  Pagination,
  SearchBox,
  SectionHeading,
  SelectField,
  Table,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { Paginated, TableColumn } from '@/types'
import { formatCurrency, formatDate } from '@/utils'

interface PriceBookRow {
  readonly id: number
  readonly description: string
  readonly unit: string
  readonly section: string | null
  readonly subsection: string | null
  /** What this system quotes: the median of every line priced for this item. */
  readonly unitMaterialCost: number | null
  readonly unitManhours: number | null
  /** How many priced lines that figure came from. */
  readonly sampleCount: number
  readonly minMaterialCost: number | null
  readonly maxMaterialCost: number | null
  /** Set by hand: this rate is ours, leave it alone on the next import. */
  readonly isPinned: boolean
}

interface ImportRow {
  readonly id: number
  readonly projectName: string
  readonly fileName: string
  readonly lineCount: number
  readonly baseBidPrice: number | null
  readonly materialTaxPct: number | null
  readonly overheadPct: number | null
  readonly profitPct: number | null
  readonly electricianRate: number | null
  readonly compositeLaborRate: number | null
  readonly totalManhours: number | null
  readonly importedAt: string | null
}

export interface PriceBookProps {
  items: Paginated<PriceBookRow>
  filters: { search: string; unit: string; section: string }
  units: readonly string[]
  sections: readonly string[]
  totals: { items: number; lines: number; imports: number }
  imports: readonly ImportRow[]
}

/**
 * The company's own rates.
 *
 * Every price this system quotes used to be a constant somebody typed into
 * code. These came off jobs that were actually bid — and each one carries the
 * spread it was derived from, so a rate can be doubted on the screen that
 * shows it rather than only after a job is lost on it.
 */
export default function PriceBook({
  items,
  filters,
  units,
  sections,
  totals,
  imports,
}: PriceBookProps) {
  const [search, setSearch] = useState(filters.search)

  const apply = useCallback((changes: Record<string, string | number>) => {
    const params = new URLSearchParams(window.location.search)

    for (const [key, value] of Object.entries(changes)) {
      const text = String(value)
      // Defaults are left out so the address stays readable.
      if (text === '' || text === 'all') params.delete(key)
      else params.set(key, text)
    }

    // Any change to what is being looked for starts again at the first page.
    if (!('page' in changes)) params.delete('page')

    router.get(`${ROUTES.priceBook}?${params.toString()}`, {}, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }, [])

  const columns: readonly TableColumn<PriceBookRow>[] = [
    {
      key: 'description',
      header: 'Item',
      render: (row) => (
        <Link
          href={`${ROUTES.priceBook}/${row.id}`}
          className="group block min-w-0 max-w-xl"
        >
          <span className="block truncate text-md text-white group-hover:text-brand">
            {row.description}
            {row.isPinned && (
              <Pin size={13} aria-label="Rate set by hand" className="ml-2 inline text-status-warning" />
            )}
          </span>
          {(row.section || row.subsection) && (
            <span className="block truncate text-2xs text-white/70">
              {[row.section, row.subsection].filter(Boolean).join(' → ')}
            </span>
          )}
        </Link>
      ),
    },
    { key: 'unit', header: 'Unit', width: 'w-20', render: (row) => row.unit },
    {
      key: 'material',
      header: 'Material / unit',
      align: 'right',
      width: 'w-36',
      render: (row) =>
        row.unitMaterialCost === null ? (
          <span className="text-white/50">—</span>
        ) : (
          // Four decimals, because conduit and conductor rates are fractions
          // of a cent per foot and rounding them here would misprice a run.
          <span className="tabular-nums">${row.unitMaterialCost.toFixed(4).replace(/0+$/, '0')}</span>
        ),
    },
    {
      key: 'manhours',
      header: 'Manhours / unit',
      align: 'right',
      width: 'w-36',
      render: (row) =>
        row.unitManhours === null ? (
          <span className="text-white/50">—</span>
        ) : (
          <span className="tabular-nums">{row.unitManhours}</span>
        ),
    },
    {
      key: 'spread',
      header: 'Priced range',
      align: 'right',
      width: 'w-44',
      render: (row) => {
        if (row.minMaterialCost === null || row.maxMaterialCost === null) {
          return <span className="text-white/50">—</span>
        }

        // One number when every job priced it the same — a range nobody has to
        // read is noise on a screen full of numbers.
        return row.minMaterialCost === row.maxMaterialCost ? (
          <span className="text-sm text-white/70">agreed</span>
        ) : (
          <span className="text-sm tabular-nums text-status-warning">
            {formatCurrency(row.minMaterialCost, 2)} – {formatCurrency(row.maxMaterialCost, 2)}
          </span>
        )
      },
    },
    {
      key: 'samples',
      header: 'Seen',
      align: 'right',
      width: 'w-20',
      render: (row) => <span className="tabular-nums text-white/85">{row.sampleCount}</span>,
    },
  ]

  const meta = items.meta

  return (
    <PageTransition>
      <Head title="Price Book" />

      <PageHeader
        title="Price Book"
        subtitle={`${totals.items} items, from ${totals.lines} priced lines across ${totals.imports} estimating workbooks`}
      />

      {/* ------------------------------------------------ Where it came from */}
      <Card accent="brand" padding="lg" className="mb-6">
        <SectionHeading
          as="h3"
          title="Imported estimates"
          subtitle="Every rate below was priced on one of these jobs"
        />

        <div className="-mx-1 overflow-x-auto">
          <table className="w-full min-w-[46rem] text-left text-sm">
            <thead className="text-2xs tracking-wide text-white/70 uppercase">
              <tr>
                <th className="px-1 pb-2">Project</th>
                <th className="px-1 pb-2 text-right">Lines</th>
                <th className="px-1 pb-2 text-right">Base bid</th>
                <th className="px-1 pb-2 text-right">Manhours</th>
                <th className="px-1 pb-2 text-right">Tax</th>
                <th className="px-1 pb-2 text-right">O/H</th>
                <th className="px-1 pb-2 text-right">Profit</th>
                <th className="px-1 pb-2 text-right">Labour</th>
                <th className="px-1 pb-2 text-right">Imported</th>
              </tr>
            </thead>
            <tbody className="text-white/90">
              {imports.map((row) => (
                <tr key={row.id} className="border-t border-hairline">
                  <td className="max-w-xs truncate px-1 py-2 text-white">{row.projectName}</td>
                  <td className="px-1 py-2 text-right tabular-nums">{row.lineCount}</td>
                  <td className="px-1 py-2 text-right tabular-nums">
                    {row.baseBidPrice === null ? '—' : formatCurrency(row.baseBidPrice, 0)}
                  </td>
                  <td className="px-1 py-2 text-right tabular-nums">
                    {row.totalManhours === null ? '—' : Math.round(row.totalManhours)}
                  </td>
                  <td className="px-1 py-2 text-right tabular-nums">
                    {row.materialTaxPct === null ? '—' : `${row.materialTaxPct}%`}
                  </td>
                  <td className="px-1 py-2 text-right tabular-nums">
                    {row.overheadPct === null ? '—' : `${row.overheadPct}%`}
                  </td>
                  <td className="px-1 py-2 text-right tabular-nums">
                    {row.profitPct === null ? '—' : `${row.profitPct}%`}
                  </td>
                  <td className="px-1 py-2 text-right tabular-nums">
                    {row.compositeLaborRate === null ? '—' : formatCurrency(row.compositeLaborRate, 0)}
                  </td>
                  <td className="px-1 py-2 text-right text-white/70">
                    {row.importedAt ? formatDate(row.importedAt) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {/* --------------------------------------------------------- Filters -- */}
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="lg:col-span-2">
          <p className="mb-2 text-md font-medium text-white">Search</p>
          <SearchBox
            value={search}
            onValueChange={setSearch}
            onSearch={(value) => apply({ search: value })}
            clearable
            placeholder="Item name, e.g. 3/4 conduit"
          />
        </div>
        <SelectField
          id="price-book-unit"
          label="Unit"
          options={[
            { label: 'All units', value: 'all' },
            ...units.map((unit) => ({ label: unit, value: unit })),
          ]}
          value={filters.unit}
          onChange={(event) => apply({ unit: event.target.value })}
        />
        <SelectField
          id="price-book-section"
          label="Section"
          options={[
            { label: 'All sections', value: 'all' },
            ...sections.map((section) => ({ label: section, value: section })),
          ]}
          value={filters.section}
          onChange={(event) => apply({ section: event.target.value })}
        />
      </div>

      {/* ------------------------------------------------------- The rates -- */}
      <Card accent="success" padding="lg">
        <Table
          columns={columns}
          rows={items.data}
          getRowId={(row) => row.id}
          emptyState={
            <EmptyState
              icon={SearchX}
              title="Nothing matches that"
              description="Try a shorter term — items are named the way the estimator wrote them."
            />
          }
        />

        {meta.last_page > 1 && (
          <Pagination
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => apply({ page })}
            summary={`Showing ${items.data.length} of ${meta.total} items`}
            withLabels
            className="mt-6"
          />
        )}
      </Card>

      <p className="mt-4 text-sm text-white/70">
        Rates are the median of every line priced for that item.{' '}
        <Badge tone="warning" size="sm">Priced range</Badge> marks the ones the jobs
        disagreed on — open an item to see every line behind its figure.
      </p>
    </PageTransition>
  )
}

PriceBook.layout = appLayout
