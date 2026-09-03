import type { DraftAddress } from '@/types'

/** A blank site row, ready to type into. */
export const emptyAddress = (): DraftAddress => ({
  label: '',
  address: '',
  latitude: null,
  longitude: null,
})
