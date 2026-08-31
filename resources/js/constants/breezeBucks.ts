import type { BreezeBucksTransactionType, Tone } from '@/types'

export const BREEZE_BUCKS_TYPE_LABEL: Record<BreezeBucksTransactionType, string> = {
  earned: 'Earned',
  redeemed: 'Redeemed',
  adjustment: 'Adjustment',
  bonus: 'Bonus',
  reversal: 'Reversal',
}

export const BREEZE_BUCKS_TYPE_TONE: Record<BreezeBucksTransactionType, Tone> = {
  earned: 'success',
  redeemed: 'info',
  adjustment: 'warning',
  bonus: 'brand',
  reversal: 'danger',
}

export const BREEZE_BUCKS_HISTORY_FILTERS = [
  { label: 'All', value: 'all' },
  { label: 'Earned', value: 'earned' },
  { label: 'Redeemed', value: 'redeemed' },
  { label: 'Adjustments', value: 'adjustment' },
] as const
