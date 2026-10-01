import { usePage } from '@inertiajs/react'
import type { SharedPageProps } from '@/types'

/**
 * What the signed-in person's role may do — see Roles & Permissions. `null` from the server means
 * their role is not governed by the matrix, which means everything.
 */
export function usePermissions(): { can: (permission: string) => boolean } {
  const { permissions } = usePage<SharedPageProps>().props

  return { can: (permission) => permissions === null || permissions === undefined || permissions.includes(permission) }
}
