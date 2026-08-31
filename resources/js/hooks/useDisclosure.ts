import { useCallback, useState } from 'react'

interface Disclosure {
  isOpen: boolean
  open: () => void
  close: () => void
  toggle: () => void
}

/** Minimal open/close state helper for modals, dialogs and dropdowns. */
export function useDisclosure(initial = false): Disclosure {
  const [isOpen, setIsOpen] = useState(initial)

  return {
    isOpen,
    open: useCallback(() => setIsOpen(true), []),
    close: useCallback(() => setIsOpen(false), []),
    toggle: useCallback(() => setIsOpen((value) => !value), []),
  }
}
