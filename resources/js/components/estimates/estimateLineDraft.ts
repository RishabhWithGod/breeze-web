import type { EstimateCategory } from '@/types'

/** Blank line used both by the "add line" card and by editing an existing one. */
export const EMPTY_LINE_DRAFT = {
  category: 'material' as EstimateCategory,
  description: '',
  unit: 'ea',
  quantity: '1',
  unit_cost: '0',
}

export type LineDraft = typeof EMPTY_LINE_DRAFT
