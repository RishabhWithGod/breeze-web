import { useEffect, useRef, type RefObject } from 'react'

/** Fires when a pointer event lands outside the returned element ref. */
export function useClickOutside<T extends HTMLElement>(
  handler: () => void,
  enabled = true,
): RefObject<T | null> {
  const ref = useRef<T | null>(null)
  const handlerRef = useRef(handler)

  // Keep the latest callback without re-binding the document listeners.
  useEffect(() => {
    handlerRef.current = handler
  }, [handler])

  useEffect(() => {
    if (!enabled) return undefined

    const onPointerDown = (event: MouseEvent | TouchEvent) => {
      const element = ref.current
      if (element && !element.contains(event.target as Node)) handlerRef.current()
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('touchstart', onPointerDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('touchstart', onPointerDown)
    }
  }, [enabled])

  return ref
}
