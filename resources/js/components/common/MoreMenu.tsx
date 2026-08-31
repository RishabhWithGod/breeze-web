import { useEffect, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
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
  // Computed from the trigger's real screen position, not CSS — the menu is
  // portalled straight to `document.body` (see below) so it's never clipped
  // by a card's own `overflow-hidden`, no matter how close to its edge the
  // trigger sits. `top` and `right`/`left` are the menu's own final pixel
  // position — deliberately not a CSS `transform` offset, since a
  // `motion.div` manages `transform` itself for its enter/exit animation and
  // would silently drop one passed through `style`.
  const [anchor, setAnchor] = useState<{ top: number; left?: number; right?: number } | null>(null)
  const containerRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!isOpen) return undefined

    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node
      if (containerRef.current?.contains(target) || menuRef.current?.contains(target)) return
      setIsOpen(false)
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

  const open = () => {
    const rect = triggerRef.current?.getBoundingClientRect()
    if (!rect) return

    // The menu hasn't rendered yet, so its height isn't measurable — this
    // over-estimates on purpose (real row height is closer to 36px), which
    // only ever costs a little extra headroom when placing it above.
    const estimatedHeight = items.length * 40 + 16
    const placeAbove = rect.bottom + estimatedHeight + 8 > window.innerHeight

    setAnchor({
      top: placeAbove ? rect.top - 6 - estimatedHeight : rect.bottom + 6,
      ...(align === 'right' ? { right: window.innerWidth - rect.right } : { left: rect.left }),
    })
    setIsOpen(true)
  }

  return (
    <div ref={containerRef} className={cn('relative', className)}>
      <button
        ref={triggerRef}
        type="button"
        aria-haspopup="menu"
        aria-expanded={isOpen}
        aria-label={label ? undefined : ariaLabel}
        disabled={disabled}
        onClick={() => (isOpen ? setIsOpen(false) : open())}
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

      {createPortal(
        <AnimatePresence>
          {isOpen && anchor && (
            <motion.div
              ref={menuRef}
              role="menu"
              initial={{ opacity: 0, y: -4 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -4 }}
              transition={{ duration: 0.14 }}
              style={anchor}
              className="fixed z-50 min-w-44 overflow-hidden rounded-panel border border-hairline-strong bg-navy-900 shadow-raised"
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
        </AnimatePresence>,
        document.body,
      )}
    </div>
  )
}
