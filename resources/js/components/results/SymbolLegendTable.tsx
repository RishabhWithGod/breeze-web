import { useMemo, useState } from 'react'
import { SearchX } from 'lucide-react'
import {
  Badge,
  EmptyState,
  FilterTabs,
  Pagination,
  SearchBox,
  Table,
} from '@/components/common'
import { useDebouncedValue, usePagination } from '@/hooks'
import type { DetectedSymbol, TableColumn } from '@/types'
import { formatNumber } from '@/utils'
import { ConfidenceMeter } from './ConfidenceMeter'

const CATEGORY_FILTERS = [
  { label: 'All', value: 'all' },
  { label: 'Lighting', value: 'Lighting' },
  { label: 'Power', value: 'Power' },
  { label: 'Data', value: 'Data' },
  { label: 'Fire Alarm', value: 'Fire Alarm' },
  { label: 'Distribution', value: 'Distribution' },
] as const

type CategoryFilter = (typeof CATEGORY_FILTERS)[number]['value']

export interface SymbolLegendTableProps {
  symbols: readonly DetectedSymbol[]
}

/** Searchable, filterable, paginated legend of every detected symbol. */
export function SymbolLegendTable({ symbols }: SymbolLegendTableProps) {
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState<CategoryFilter>('all')
  const debouncedQuery = useDebouncedValue(query)

  const filtered = useMemo(() => {
    const needle = debouncedQuery.trim().toLowerCase()

    return symbols.filter((symbol) => {
      const matchesCategory = category === 'all' || symbol.category === category
      const matchesQuery =
        needle.length === 0 ||
        symbol.name.toLowerCase().includes(needle) ||
        symbol.code.toLowerCase().includes(needle)
      return matchesCategory && matchesQuery
    })
  }, [symbols, category, debouncedQuery])

  const { page, pageCount, pageItems, rangeStart, rangeEnd, total, setPage } =
    usePagination(filtered, 6)

  const counts = useMemo(() => {
    const result: Partial<Record<CategoryFilter, number>> = { all: symbols.length }
    for (const symbol of symbols) {
      result[symbol.category] = (result[symbol.category] ?? 0) + 1
    }
    return result
  }, [symbols])

  const columns: TableColumn<DetectedSymbol>[] = [
    {
      key: 'symbol',
      header: 'Symbol',
      render: (symbol) => (
        <div className="flex items-center gap-3">
          <span className="grid size-9 shrink-0 place-items-center rounded-panel border border-brand/40 bg-brand/10 text-sm font-bold text-brand">
            {symbol.code}
          </span>
          <span className="font-medium text-white">{symbol.name}</span>
        </div>
      ),
    },
    {
      key: 'category',
      header: 'Category',
      render: (symbol) => (
        <Badge tone="neutral" size="sm">
          {symbol.category}
        </Badge>
      ),
    },
    {
      key: 'count',
      header: 'Count',
      align: 'right',
      width: 'w-28',
      render: (symbol) => (
        <span className="font-semibold tabular-nums text-white">
          {formatNumber(symbol.count)}
        </span>
      ),
    },
    {
      key: 'unit',
      header: 'Unit',
      align: 'center',
      width: 'w-20',
      render: (symbol) => <span className="text-white/60">{symbol.unit}</span>,
    },
    {
      key: 'confidence',
      header: 'Confidence',
      width: 'w-56',
      render: (symbol) => <ConfidenceMeter value={symbol.confidence} />,
    },
  ]

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <FilterTabs
          options={CATEGORY_FILTERS}
          value={category}
          onChange={setCategory}
          counts={counts}
        />
        <SearchBox
          value={query}
          onValueChange={setQuery}
          placeholder="Search symbols…"
          containerClassName="xl:max-w-xs"
          aria-label="Search detected symbols"
        />
      </div>

      <Table
        columns={columns}
        rows={pageItems}
        getRowId={(symbol) => symbol.id}
        caption="Detected electrical symbols with counts and model confidence"
        emptyState={
          <EmptyState
            size="sm"
            icon={SearchX}
            title="No symbols match your filters"
            description="Try a different category or clear the search query."
          />
        }
      />

      <Pagination
        page={page}
        pageCount={pageCount}
        onPageChange={setPage}
        summary={
          total === 0
            ? 'No symbols to display'
            : `Showing ${rangeStart}–${rangeEnd} of ${total} symbol classes`
        }
      />
    </div>
  )
}
