import { useEffect, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { X } from 'lucide-react'
import { cn } from '@/utils'

const SIZES = {
  sm: 'max-w-md',
  md: 'max-w-xl',
  lg: 'max-w-3xl',
  xl: 'max-w-5xl',
} as const

export interface ModalProps {
  isOpen: boolean
  onClose: () => void
  title?: ReactNode
  description?: ReactNode
  size?: keyof typeof SIZES
  /** Sticky action row rendered at the bottom of the panel. */
  footer?: ReactNode
  hideCloseButton?: boolean
  children?: ReactNode
}

/**
 * Accessible dialog rendered in a portal. Handles Escape, scroll locking and
 * backdrop dismissal; the panel itself is animated with Framer Motion.
 */
export function Modal({
  isOpen,
  onClose,
  title,
  description,
  size = 'md',
  footer,
  hideCloseButton = false,
  children,
}: ModalProps) {
  useEffect(() => {
    if (!isOpen) return undefined

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }

    // Scroll lock via a utility class keeps all styling in the stylesheet.
    document.body.classList.add('overflow-hidden')
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.body.classList.remove('overflow-hidden')
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [isOpen, onClose])

  return createPortal(
    <AnimatePresence>
      {isOpen && (
        <div className="fixed inset-0 z-100 flex items-end justify-center p-4 sm:items-center">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            onClick={onClose}
            className="absolute inset-0 bg-navy-950/70 backdrop-blur-sm"
            aria-hidden
          />

          <motion.div
            role="dialog"
            aria-modal="true"
            aria-label={typeof title === 'string' ? title : 'Dialog'}
            initial={{ opacity: 0, y: 24, scale: 0.97 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 16, scale: 0.98 }}
            transition={{ duration: 0.26, ease: [0.22, 1, 0.36, 1] }}
            className={cn(
              'relative w-full overflow-hidden rounded-card border border-hairline-strong',
              'grad-ocean-solid shadow-raised',
              SIZES[size],
            )}
          >
            {(title || !hideCloseButton) && (
              <div className="flex items-start justify-between gap-4 border-b border-hairline px-6 py-5">
                <div className="min-w-0">
                  {title && (
                    <h2 className="text-xl font-semibold text-white">{title}</h2>
                  )}
                  {description && (
                    <p className="mt-1 text-md text-white/90">{description}</p>
                  )}
                </div>
                {!hideCloseButton && (
                  <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close dialog"
                    className="rounded-full p-1.5 text-white/90 transition-colors hover:bg-white/10 hover:text-white"
                  >
                    <X size={20} aria-hidden />
                  </button>
                )}
              </div>
            )}

            <div className="max-h-[70dvh] overflow-y-auto px-6 py-5 text-md text-white">
              {children}
            </div>

            {footer && (
              <div className="flex flex-wrap items-center justify-end gap-3 border-t border-hairline bg-navy-950/25 px-6 py-4">
                {footer}
              </div>
            )}
          </motion.div>
        </div>
      )}
    </AnimatePresence>,
    document.body,
  )
}
