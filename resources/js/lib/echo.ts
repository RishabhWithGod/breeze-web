import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

type AppEcho = Echo<'reverb'>

let echo: AppEcho | null = null

function csrfToken(): string {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

/**
 * The one Echo/WebSocket connection for the whole app.
 *
 * Every page or component that needs realtime updates calls this instead of
 * constructing its own `Echo` — one socket, subscribed to whatever channels
 * are currently in view, not one per component. `pusher-js` (Reverb speaks
 * its protocol) is attached to `window.Pusher` because Echo's Pusher
 * connector expects to find it there.
 *
 * Private channels are authorized against `/broadcasting/auth`, the same
 * session cookie + CSRF token every other mutation in this app already
 * sends — there is no separate realtime auth system to keep in sync.
 */
export function getEcho(): AppEcho {
  if (echo) {
    return echo
  }

  window.Pusher = Pusher

  const scheme = import.meta.env['VITE_REVERB_SCHEME'] ?? 'https'
  const port = Number(import.meta.env['VITE_REVERB_PORT'] ?? (scheme === 'https' ? 443 : 80))

  echo = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: import.meta.env['VITE_REVERB_APP_KEY'],
    wsHost: import.meta.env['VITE_REVERB_HOST'],
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    auth: {
      headers: {
        'X-CSRF-TOKEN': csrfToken(),
      },
    },
  })

  return echo
}

/** Tears down the connection entirely — only used on sign-out. */
export function disconnectEcho(): void {
  echo?.disconnect()
  echo = null
}
