import type { InvoiceLineCategory } from '@/types'
import { cn } from '@/utils'
import { LINE_CATEGORY } from './lineTypes'

/** What an invoice line is for, as a pill — the same on the create screen and the saved invoice. */
export function LineTypePill({ category }: { category: string | null }) {
  const known = category !== null && category in LINE_CATEGORY ? (category as InvoiceLineCategory) : 'other'

  return (
    <span
      className={cn(
        'inline-flex rounded-full border px-3 py-0.5 text-xs font-medium whitespace-nowrap',
        LINE_CATEGORY[known].classes,
      )}
    >
      {LINE_CATEGORY[known].label}
    </span>
  )
}
