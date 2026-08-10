import { useId, useState, type ReactNode } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { ChevronDown, Cpu } from 'lucide-react'
import { cn } from '@/utils'

export interface AdvancedDetailsProps {
  /** Defaults to "Advanced details"; override only when the section is narrower. */
  label?: string
  /** One line saying what is inside, so nobody has to open it to find out. */
  hint?: string
  /** Opens on mount. Off by default — that is the point of the component. */
  defaultOpen?: boolean
  /**
   * Controls the panel from outside, for when another control (a menu item) opens
   * it. Supply `onOpenChange` alongside, or the header toggle stops working.
   */
  open?: boolean
  onOpenChange?: (open: boolean) => void
  /** Sits beside the label, e.g. a count of the fields inside. */
  meta?: ReactNode
  className?: string
  panelClassName?: string
  children: ReactNode
}

/**
 * Collapses the engine's technical output out of the way.
 *
 * An estimator pricing a job needs the symbol, the count and a decision. Bounding
 * boxes, detector provenance, confidence and pipeline stages are how the number was
 * arrived at — real information, and occasionally the thing that settles an
 * argument, but not what the screen is for. It stays one click away rather than
 * competing with the work.
 *
 * Closed by default and never persisted: the default has to be the calm one, or the
 * whole point is lost the first time somebody opens it.
 */
export function AdvancedDetails({
  label = 'Advanced details',
  hint,
  defaultOpen = false,
  open,
  onOpenChange,
  meta,
  className,
  panelClassName,
  children,
}: AdvancedDetailsProps) {
  const [uncontrolled, setUncontrolled] = useState(defaultOpen)
  const panelId = useId()

  // Controlled when a parent supplies `open`; self-managed otherwise.
  const isOpen = open ?? uncontrolled
  const setIsOpen = (next: boolean) => {
    if (open === undefined) setUncontrolled(next)
    onOpenChange?.(next)
  }

  return (
    <div className={cn('rounded-panel border border-hairline bg-white/4', className)}>
      <button
        type="button"
        aria-expanded={isOpen}
        aria-controls={panelId}
        onClick={() => setIsOpen(!isOpen)}
        className={cn(
          'flex w-full items-center gap-2.5 px-3 py-2.5 text-left transition-colors',
          'hover:bg-white/6 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-brand/60',
          isOpen ? 'rounded-t-panel' : 'rounded-panel',
        )}
      >
        <Cpu size={14} aria-hidden className="shrink-0 text-white/65" />

        <span className="min-w-0 flex-1">
          <span className="block text-sm font-medium text-white">{label}</span>
          {hint && !isOpen && (
            <span className="block truncate text-2xs text-white/65">{hint}</span>
          )}
        </span>

        {meta && <span className="shrink-0 text-2xs text-white/65">{meta}</span>}

        <ChevronDown
          size={15}
          aria-hidden
          className={cn(
            'shrink-0 text-white/70 transition-transform duration-200',
            isOpen && 'rotate-180',
          )}
        />
      </button>

      <AnimatePresence initial={false}>
        {isOpen && (
          <motion.div
            id={panelId}
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
            className="overflow-hidden"
          >
            <div className={cn('border-t border-hairline px-3 py-3', panelClassName)}>
              {children}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}

export interface DetailRowProps {
  label: string
  children: ReactNode
  /** Renders the value in a monospace face — ids, coordinates, raw values. */
  mono?: boolean
}

/**
 * One label/value pair inside an `AdvancedDetails` panel.
 *
 * A definition list rather than a table: these are attributes of one thing, they
 * wrap on narrow cards, and a screen reader reads the pairing correctly.
 */
export function DetailRow({ label, children, mono = false }: DetailRowProps) {
  return (
    <div className="flex items-start justify-between gap-3 py-1">
      <dt className="shrink-0 text-2xs text-white/70">{label}</dt>
      <dd
        className={cn(
          'min-w-0 text-right text-2xs break-words text-white',
          mono && 'font-mono',
        )}
      >
        {children}
      </dd>
    </div>
  )
}
