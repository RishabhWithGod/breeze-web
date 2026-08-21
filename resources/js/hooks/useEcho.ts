import { useEffect, useRef, useState } from 'react'
import { getEcho } from '@/lib/echo'

type ConnectionState = 'initializing' | 'connecting' | 'connected' | 'unavailable' | 'disconnected'

/** Loose enough to cover every event payload this app broadcasts. */
type EventHandler = (payload: Record<string, unknown>) => void

/**
 * Subscribes to a private channel for as long as the component is mounted,
 * routing named events to the given handlers.
 *
 * `events` is read through a ref so callers can pass a fresh object literal
 * on every render without re-subscribing — only `channelName` changing
 * tears down the old subscription and opens a new one (e.g. navigating from
 * one job's page to another's). The ref is updated in its own effect
 * (rather than during render) so a listener callback firing between renders
 * never reads a value render hasn't committed yet.
 */
export function usePrivateChannel(channelName: string | null, events: Record<string, EventHandler>): void {
  const eventsRef = useRef(events)

  useEffect(() => {
    eventsRef.current = events
  }, [events])

  useEffect(() => {
    if (!channelName) {
      return
    }

    const echo = getEcho()
    const channel = echo.private(channelName)

    const names = Object.keys(eventsRef.current)
    names.forEach((name) => {
      // A leading dot tells Echo this is the exact wire name to bind to.
      // Without it, Echo prepends its default namespace (`App.Events`) and
      // turns every other dot into a backslash — silently listening for
      // `App\Events\job\status-changed` instead of the `job.status-changed`
      // this app's `broadcastAs()` methods actually send.
      channel.listen(`.${name}`, (payload: Record<string, unknown>) => {
        eventsRef.current[name]?.(payload)
      })
    })

    return () => {
      echo.leave(channelName)
    }
  }, [channelName])
}

interface PusherConnection {
  state: string
  bind(event: 'state_change', callback: (states: { current: string }) => void): void
  unbind(event: 'state_change', callback: (states: { current: string }) => void): void
}

function currentConnectionState(): ConnectionState {
  const pusher = (getEcho().connector as unknown as { pusher: { connection: PusherConnection } }).pusher

  return pusher.connection.state as ConnectionState
}

/**
 * The socket's current state, plus an `onReconnect` hook for resyncing
 * whatever the caller considers authoritative — realtime is a notification
 * mechanism, not the source of truth, so a browser that was disconnected
 * must never assume it received every event it missed while it was gone.
 */
export function useEchoConnectionState(onReconnect?: () => void): ConnectionState {
  const [state, setState] = useState<ConnectionState>(currentConnectionState)
  const everConnectedRef = useRef(state === 'connected')
  const onReconnectRef = useRef(onReconnect)

  useEffect(() => {
    onReconnectRef.current = onReconnect
  }, [onReconnect])

  useEffect(() => {
    const echo = getEcho()
    // Reverb speaks the Pusher protocol; Echo's Reverb connector exposes the
    // same `pusher-js` client under `.pusher`, which is where connection
    // state actually lives.
    const pusher = (echo.connector as unknown as { pusher: { connection: PusherConnection } }).pusher

    const handleStateChange = (states: { current: string }) => {
      const next = states.current as ConnectionState
      setState(next)

      if (next === 'connected') {
        if (everConnectedRef.current) {
          onReconnectRef.current?.()
        }
        everConnectedRef.current = true
      }
    }

    pusher.connection.bind('state_change', handleStateChange)

    return () => {
      pusher.connection.unbind('state_change', handleStateChange)
    }
  }, [])

  return state
}
