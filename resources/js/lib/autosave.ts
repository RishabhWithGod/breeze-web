import { useEffect, useRef } from 'react'
import { router } from '@inertiajs/react'

/**
 * Sends a change to the server without going through an Inertia visit, so it can finish while the
 * page is being left. Resolves once the server has answered (or failed — the caller then simply
 * carries on; the edit is also kept on screen until a save succeeds).
 */
export async function saveInBackground(url: string, payload: Record<string, unknown>): Promise<boolean> {
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''

  try {
    const response = await fetch(url, {
      method: 'PUT',
      keepalive: true,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': token,
      },
      body: JSON.stringify(payload),
    })

    return response.ok
  } catch {
    return false
  }
}

export interface AutosaveOptions {
  /** Something is being edited right now. */
  enabled: boolean
  /** What is on screen differs from what is saved, and is complete enough to save. */
  dirty: boolean
  /** Changes whenever the edit does, so typing keeps pushing the save back. */
  signature: string
  /** Saves from the screen, updating it in place (no navigation, edit stays open). */
  save: () => void
  /** Saves for good, even if the page is being left; resolves when the server has it. */
  flush: () => Promise<unknown>
  delayMs?: number
}

/**
 * Keeps an in-progress edit from ever being lost.
 *
 * - Saves on its own shortly after typing stops.
 * - Before following any link (Continue, Back, the sidebar) it first saves what is pending and only
 *   then goes where the link goes — the edit is on the server before the next screen asks for it.
 * - Also saves when the tab is closed or reloaded.
 */
export function useAutosave({ enabled, dirty, signature, save, flush, delayMs = 900 }: AutosaveOptions): void {
  const latest = useRef({ enabled, dirty, save, flush })
  latest.current = { enabled, dirty, save, flush }

  useEffect(() => {
    if (!enabled || !dirty) return undefined

    const timer = window.setTimeout(() => latest.current.save(), delayMs)

    return () => window.clearTimeout(timer)
  }, [enabled, dirty, signature, delayMs])

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      const { enabled: on, dirty: pending, flush: send } = latest.current

      if (!on || !pending || event.defaultPrevented || event.button !== 0) return
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

      const anchor = (event.target as HTMLElement | null)?.closest('a[href]') as HTMLAnchorElement | null

      if (!anchor || (anchor.target && anchor.target !== '_self') || anchor.hasAttribute('download')) return
      if (anchor.origin !== window.location.origin || anchor.getAttribute('href')?.startsWith('#')) return

      event.preventDefault()
      event.stopPropagation()

      const destination = anchor.pathname + anchor.search + anchor.hash

      void Promise.resolve(send()).finally(() => router.visit(destination))
    }

    const onPageHide = () => {
      const { enabled: on, dirty: pending, flush: send } = latest.current

      if (on && pending) void send()
    }

    document.addEventListener('click', onClick, true)
    window.addEventListener('pagehide', onPageHide)

    return () => {
      document.removeEventListener('click', onClick, true)
      window.removeEventListener('pagehide', onPageHide)
      onPageHide()
    }
  }, [])
}
