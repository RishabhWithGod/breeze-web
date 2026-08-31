import { useCallback, useSyncExternalStore } from 'react'

/**
 * Subscribes to a CSS media query — used for layout-only behaviour switches.
 * Backed by `useSyncExternalStore` so React stays in sync with the browser
 * without a state-updating effect.
 */
export function useMediaQuery(query: string): boolean {
  const subscribe = useCallback(
    (onStoreChange: () => void) => {
      const mediaQuery = window.matchMedia(query)
      mediaQuery.addEventListener('change', onStoreChange)
      return () => mediaQuery.removeEventListener('change', onStoreChange)
    },
    [query],
  )

  const getSnapshot = useCallback(() => window.matchMedia(query).matches, [query])

  // Server snapshot: assume the query does not match during SSR/prerender.
  return useSyncExternalStore(subscribe, getSnapshot, () => false)
}

/** Tailwind `lg` breakpoint — the point where the sidebar becomes permanent. */
export const useIsDesktop = (): boolean => useMediaQuery('(min-width: 1024px)')
