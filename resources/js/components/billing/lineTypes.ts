import type { InvoiceLineCategory } from '@/types'

/** What an invoice line is for, and how its pill is coloured. */
export const LINE_CATEGORY: Record<InvoiceLineCategory, { label: string; classes: string }> = {
  labor: { label: 'Labor', classes: 'border-status-success/60 bg-status-success/12 text-status-success' },
  material: { label: 'Material', classes: 'border-status-blue/60 bg-status-blue/12 text-status-blue' },
  equipment: { label: 'Equipment', classes: 'border-brand/60 bg-brand/10 text-brand' },
  other: { label: 'Other', classes: 'border-hairline-strong bg-white/10 text-white/85' },
}

export const LINE_CATEGORY_OPTIONS = (Object.keys(LINE_CATEGORY) as InvoiceLineCategory[]).map((value) => ({
  value,
  label: LINE_CATEGORY[value].label,
}))
