import { usePage } from '@inertiajs/react'
import type { SharedPageProps } from '@/types'

/** Breezy's view of who is signed in. Every module is offered; the server still decides what opens. */
export function useRole(): {
  role: string
  real: string
  field: boolean
  previewing: boolean
  me: undefined
  canPreview: boolean
  can: (permission?: string) => boolean
} {
  const user = usePage<SharedPageProps>().props.auth.user
  const role = user?.role ?? 'Owner'
  return { role, real: role, field: false, previewing: false, me: undefined, canPreview: false, can: () => true }
}
