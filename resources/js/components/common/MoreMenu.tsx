import { useEffect, useRef, useState, type ReactNode } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { MoreHorizontal, type LucideIcon } from 'lucide-react'
import { cn } from '@/utils'

export interface MoreMenuItem {
  label: string
  icon?: LucideIcon
  onSelect: () => void
  disabled?: boolean
  /** Renders the item in the danger tone. */
  destructive?: boolean
}

export interface MoreMenuProps {
  items: readonly MoreMenuItem[]
  /** Accessible name; the trigger is icon-only when there is no `label`. */
  ariaLabel?: string
  label?: ReactNode
  align?: 'left' | 'right'
  disabled?: boolean
  className?: string
}

/**
 * The secondary actions on a card, folded behind one control.
 *
 * A card with six equally-weighted buttons makes the reader choose before they can
 * act. Two decisions stay on the surface; everything else lives here, one click
 * away and in a predictable order.
 *
 * Closes on outside click and on Escape, and returns focus to the trigger — the
 * menu is reachable and escapable by keyboard alone.
 */
export function MoreMenu({
  items,
  ariaLabel = 'More actions',
  label,
  align = 'right',
  disabled = false,
  className,
}: MoreMenuProps) {
  const [isOpen, setIsOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!isOpen) return undefined

    const onPointerDown = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setIsOpen(false)
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return

      setIsOpen(false)
      triggerRef.current?.focus()
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [isOpen])

  return (
    <div ref={containerRef} className={cn('relative', className)}>
      <button
        ref={triggerRef}
        type="button"
        aria-haspopup="menu"
        aria-expanded={isOpen}
        aria-label={label ? undefined : ariaLabel}
        disabled={disabled}
        onClick={() => setIsOpen((open) => !open)}
        className={cn(
          'inline-flex w-full items-center justify-center gap-2 rounded-panel border border-hairline-strong',
          'glass-strong px-3 py-1.5 text-sm font-medium text-white transition-colors',
          'hover:border-brand/60 hover:bg-white/20',
          'disabled:cursor-not-allowed disabled:opacity-50',
        )}
      >
        {label ?? <MoreHorizontal size={15} aria-hidden />}
        {label && <MoreHorizontal size={14} aria-hidden />}
      </button>

      <AnimatePresence>
        {isOpen && (
          <motion.div
            role="menu"
            initial={{ opacity: 0, y: -4 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -4 }}
            transition={{ duration: 0.14 }}
            className={cn(
              'absolute z-50 mt-1.5 min-w-44 overflow-hidden rounded-panel border border-hairline-strong',
              'bg-navy-900 shadow-raised',
              align === 'right' ? 'right-0' : 'left-0',
            )}
          >
            {items.map((item) => (
              <button
                key={item.label}
                type="button"
                role="menuitem"
                disabled={item.disabled}
                onClick={() => {
                  setIsOpen(false)
                  item.onSelect()
                }}
                className={cn(
                  'flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm transition-colors',
                  'disabled:cursor-not-allowed disabled:opacity-40',
                  item.destructive
                    ? 'text-red-300 hover:bg-status-danger/15'
                    : 'text-white hover:bg-white/10 hover:text-white',
                )}
              >
                {item.icon && <item.icon size={14} aria-hidden className="shrink-0" />}
                {item.label}
              </button>
            ))}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
