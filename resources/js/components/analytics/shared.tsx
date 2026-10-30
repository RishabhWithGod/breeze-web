import { useMemo, useState, type ReactNode } from 'react'
import { ArrowDown, ArrowUp } from 'lucide-react'
import { cn } from '@/utils'

export function StatBlock({
  label,
  value,
  detail,
  note,
  noteTone,
  onClick,
}: {
  label: string
  value: string
  detail?: string
  note?: string
  noteTone?: 'success' | 'danger' | 'neutral'
  onClick?: () => void
}) {
  const Wrapper = onClick ? 'button' : 'div'

  return (
    <Wrapper
      {...(onClick ? { type: 'button' as const, onClick } : {})}
      className={cn(
        'rounded-panel border border-hairline bg-white/4 p-4 text-left',
        onClick && 'transition-colors hover:bg-white/8',
      )}
    >
      <p className="text-2xs tracking-wide text-white/70 uppercase">{label}</p>
      <p className="mt-1 text-xl font-bold text-white">{value}</p>
      {detail && <p className="mt-0.5 text-sm text-white/75">{detail}</p>}
      {note && (
        <p
          className={cn(
            'mt-1 text-sm font-medium',
            noteTone === 'danger' ? 'text-status-danger' : noteTone === 'success' ? 'text-status-success' : 'text-white/70',
          )}
        >
          {note}
        </p>
      )}
    </Wrapper>
  )
}

export interface SortableColumn<T> {
  readonly key: string
  readonly header: string
  readonly align?: 'left' | 'right'
  /** The value rows are sorted by; omit to make the column unsortable. */
  readonly sortValue?: (row: T) => number | string | null
  readonly render: (row: T) => ReactNode
}

/** A plain sortable table — click a header to sort, again to reverse. */
export function SortableTable<T>({
  columns,
  rows,
  getRowId,
  initialSort,
  emptyText,
}: {
  columns: readonly SortableColumn<T>[]
  rows: readonly T[]
  getRowId: (row: T) => string | number
  initialSort?: { key: string; dir: 'asc' | 'desc' }
  emptyText: string
}) {
  const [sort, setSort] = useState(initialSort ?? null)

  const sorted = useMemo(() => {
    const column = columns.find((c) => c.key === sort?.key)
    if (!column?.sortValue || !sort) return rows

    const read = column.sortValue
    return [...rows].sort((a, b) => {
      const av = read(a)
      const bv = read(b)
      if (av === bv) return 0
      if (av === null) return 1
      if (bv === null) return -1
      const result = typeof av === 'string' && typeof bv === 'string' ? av.localeCompare(bv) : Number(av) - Number(bv)
      return sort.dir === 'asc' ? result : -result
    })
  }, [columns, rows, sort])

  if (rows.length === 0) return <p className="py-6 text-center text-sm text-white/70">{emptyText}</p>

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[640px] border-collapse text-sm">
        <thead>
          <tr className="border-b border-hairline text-2xs tracking-wide text-white/70 uppercase">
            {columns.map((column) => {
              const active = sort?.key === column.key
              return (
                <th key={column.key} className={cn('px-3 py-2 font-medium', column.align === 'right' ? 'text-right' : 'text-left')}>
                  {column.sortValue ? (
                    <button
                      type="button"
                      onClick={() => setSort({ key: column.key, dir: active && sort?.dir === 'desc' ? 'asc' : 'desc' })}
                      className="inline-flex items-center gap-1 uppercase hover:text-white"
                    >
                      {column.header}
                      {active && (sort?.dir === 'asc' ? <ArrowUp size={12} aria-hidden /> : <ArrowDown size={12} aria-hidden />)}
                    </button>
                  ) : (
                    column.header
                  )}
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody>
          {sorted.map((row) => (
            <tr key={getRowId(row)} className="border-b border-hairline/60 text-white/90 last:border-0">
              {columns.map((column) => (
                <td key={column.key} className={cn('px-3 py-2.5', column.align === 'right' ? 'text-right tabular-nums' : 'text-left')}>
                  {column.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
