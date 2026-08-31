import type { ReactNode } from 'react'
import { motion } from 'framer-motion'
import type { TableColumn } from '@/types'
import { cn } from '@/utils'

const ALIGNMENTS = {
  left: 'text-left',
  center: 'text-center',
  right: 'text-right',
} as const

export interface TableProps<T> {
  columns: readonly TableColumn<T>[]
  rows: readonly T[]
  /** Stable React key per row — database ids are numbers. */
  getRowId: (row: T, index: number) => string | number
  /** Rendered in place of the body when there are no rows. */
  emptyState?: ReactNode
  /** Compact row padding for dense data. */
  dense?: boolean
  /** `plain` keeps header labels in title case instead of small-caps. */
  headerVariant?: 'uppercase' | 'plain'
  /**
   * `spaced` gives each row its own floating card (default).
   * `lined` joins rows into one continuous block split by hairlines.
   */
  variant?: 'spaced' | 'lined'
  caption?: string
  className?: string
}

/**
 * Generic, horizontally scrollable data table. Column rendering is delegated to
 * the caller so no formatting logic lives here.
 */
export function Table<T>({
  columns,
  rows,
  getRowId,
  emptyState,
  dense = false,
  headerVariant = 'uppercase',
  variant = 'spaced',
  caption,
  className,
}: TableProps<T>) {
  const isLined = variant === 'lined'

  if (rows.length === 0 && emptyState) {
    return <div className={className}>{emptyState}</div>
  }

  return (
    <div
      className={cn(
        '-mx-2 overflow-x-auto px-2 pb-2 sm:mx-0 sm:px-0',
        className,
      )}
    >
      <table
        className={cn(
          'w-full min-w-3xl text-left',
          isLined
            ? 'overflow-hidden rounded-panel border-separate border-spacing-0'
            : 'border-separate border-spacing-y-1',
        )}
      >
        {caption && <caption className="sr-only">{caption}</caption>}

        <thead>
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                className={cn(
                  'px-4 py-3 font-semibold text-white',
                  isLined
                    ? 'border-b border-hairline-strong bg-ocean-600/35'
                    : 'bg-ocean-600/45 first:rounded-l-panel last:rounded-r-panel',
                  headerVariant === 'uppercase'
                    ? 'text-sm tracking-wide uppercase'
                    : 'text-md',
                  ALIGNMENTS[column.align ?? 'left'],
                  column.width,
                )}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>

        <tbody>
          {rows.map((row, rowIndex) => (
            <motion.tr
              key={getRowId(row, rowIndex)}
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.25, delay: Math.min(rowIndex, 8) * 0.03 }}
              className="group"
            >
              {columns.map((column) => (
                <td
                  key={column.key}
                  className={cn(
                    'text-md text-white transition-colors group-hover:bg-white/12',
                    isLined
                      ? 'border-b border-hairline bg-white/3 group-last:border-b-0'
                      : 'border-y border-hairline bg-white/6 first:rounded-l-panel first:border-l last:rounded-r-panel last:border-r',
                    dense ? 'px-4 py-2.5' : 'px-4 py-4',
                    ALIGNMENTS[column.align ?? 'left'],
                  )}
                >
                  {column.render(row, rowIndex)}
                </td>
              ))}
            </motion.tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
