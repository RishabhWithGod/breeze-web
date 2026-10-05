import { createElement, type ReactNode } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { ChevronDown } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Card } from './Card'
import { cardAccent } from './cardStyles'
import { IconBubble } from './IconBubble'
import type { Tone } from '@/types'
import { cn } from '@/utils'

export interface CollapsibleCardProps {
  title: string
  subtitle?: string
  icon: LucideIcon
  /** Colours the stripe and the icon — what makes one card not the next. */
  tone?: Tone
  /**
   * Said in the header, so a closed card still tells you something. A count, a
   * total — whatever answers "is there anything in here for me".
   */
  summary?: ReactNode
  /** Buttons for this section. Only drawn while it is open. */
  actions?: ReactNode
  /** Pinned to the top-right of the open body, opposite `actions`. */
  corner?: ReactNode
  /**
   * Drops the lit border and left stripe, so the card sits like any plain card
   * on the screen, and the icon takes the screen's standard blue tile.
   */
  plain?: boolean
  isOpen: boolean
  onToggle: () => void
  children: ReactNode
  className?: string
}

/**
 * A section of a long screen, opened one at a time.
 *
 * The estimate screen is five or six tables stacked down a page, and reading
 * any one of them meant scrolling past the rest. Closed by default, each says
 * what it holds in its header, so the page is a contents list you open rather
 * than a wall you scroll.
 *
 * The whole header is the control — a chevron alone is a small target for
 * something the size of a card.
 */
export function CollapsibleCard({
  title,
  subtitle,
  icon,
  tone = 'brand',
  summary,
  actions,
  corner,
  plain = false,
  isOpen,
  onToggle,
  children,
  className,
}: CollapsibleCardProps) {
  const panelId = `section-${title.toLowerCase().replaceAll(/[^a-z0-9]+/g, '-')}`

  return (
    <Card
      padding="none"
      className={cn('relative overflow-hidden', !plain && cardAccent(tone), className)}
    >
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={isOpen}
        aria-controls={panelId}
        className="flex w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-white/4 sm:px-6"
      >
        {plain ? (
          // The same icon tile the job screen's stat and progress cards wear.
          <span className="grid size-9 shrink-0 place-items-center rounded-full bg-ocean-600/60 text-brand ring-1 ring-brand/25">
            {createElement(icon, { size: 16, 'aria-hidden': true })}
          </span>
        ) : (
          <IconBubble icon={icon} tone={tone} size="sm" />
        )}

        <span className="min-w-0 flex-1">
          <span className="block truncate font-semibold text-white">{title}</span>
          {subtitle && (
            <span className="mt-0.5 block truncate text-sm text-white/65">{subtitle}</span>
          )}
        </span>

        {summary && (
          <span className="hidden shrink-0 text-md text-white/85 sm:block">{summary}</span>
        )}

        <ChevronDown
          size={20}
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
            key="body"
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="overflow-hidden"
          >
            <div className="border-t border-hairline px-5 py-5 sm:px-6">
              {(actions || corner) && (
                <div className="mb-4 flex flex-wrap items-center gap-2">
                  {actions}
                  {corner && <div className="ml-auto">{corner}</div>}
                </div>
              )}
              {children}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </Card>
  )
}
