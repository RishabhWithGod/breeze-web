import type { DraftAddress } from '@/types'

/** A blank site row, ready to type into. */
export const emptyAddress = (): DraftAddress => ({
  label: '',
  address: '',
  site_type: '',
  latitude: null,
  longitude: null,
  place_id: null,
})
