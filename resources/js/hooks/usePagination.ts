import { useMemo, useState } from 'react'
import { PAGE_SIZE } from '@/constants'

interface PaginationResult<T> {
  page: number
  pageCount: number
  pageItems: T[]
  rangeStart: number
  rangeEnd: number
  total: number
  setPage: (page: number) => void
  next: () => void
  previous: () => void
}

/** Client-side pagination over an in-memory collection. */
export function usePagination<T>(
  items: readonly T[],
  pageSize: number = PAGE_SIZE,
): PaginationResult<T> {
  const [requestedPage, setRequestedPage] = useState(1)
  const pageCount = Math.max(1, Math.ceil(items.length / pageSize))

  // Clamp during render so filtering never leaves the cursor out of range.
  const page = Math.min(Math.max(1, requestedPage), pageCount)

  const pageItems = useMemo(
    () => items.slice((page - 1) * pageSize, page * pageSize),
    [items, page, pageSize],
  )

  return {
    page,
    pageCount,
    pageItems,
    total: items.length,
    rangeStart: items.length === 0 ? 0 : (page - 1) * pageSize + 1,
    rangeEnd: Math.min(page * pageSize, items.length),
    setPage: (next) => setRequestedPage(Math.min(Math.max(1, next), pageCount)),
    next: () => setRequestedPage(Math.min(page + 1, pageCount)),
    previous: () => setRequestedPage(Math.max(page - 1, 1)),
  }
}
