import { useCallback } from 'react'
import { router, usePage } from '@inertiajs/react'
import { useEchoConnectionState, usePrivateChannel } from '@/hooks'
import type { SharedPageProps } from '@/types'

/**
 * Keeps the two personal, always-shared props — the notification bell and
 * the active-timer indicator — in sync in realtime, without either of those
 * components needing to know anything about WebSockets.
 *
 * Mounted once in the app shell (not per-page), subscribed to this user's
 * own `user.{id}` channel for as long as they're signed in. On a real
 * `timer.state-changed` or `notification.created` event it asks Inertia to
 * re-fetch just those two shared props — a small JSON request, not a full
 * page reload — which is what makes `NotificationsMenu`/`TimerIndicator`
 * update purely by re-rendering from the refreshed `usePage()` props.
 *
 * The same reload also runs whenever the socket reconnects, since a
 * connection drop means any events that happened while it was down were
 * simply never received — realtime is a notification mechanism here, not
 * the source of truth, so a reconnect always resyncs from the database
 * rather than assuming nothing was missed.
 */
export function RealtimeUserSync() {
  const userId = usePage<SharedPageProps>().props.auth.user?.id ?? null

  const resync = useCallback(() => {
    router.reload({ only: ['activeTimer', 'notifications', 'unreadNotificationCount'] })
  }, [])

  usePrivateChannel(userId ? `user.${userId}` : null, {
    'timer.state-changed': resync,
    'notification.created': resync,
  })

  useEchoConnectionState(userId ? resync : undefined)

  return null
}
