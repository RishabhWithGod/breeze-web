import type { ReactNode } from 'react'
import { motion } from 'framer-motion'
import { Inbox, type LucideIcon } from 'lucide-react'
import { cn } from '@/utils'

export interface EmptyStateProps {
  icon?: LucideIcon
  title: string
  description?: ReactNode
  /** Primary/secondary buttons rendered under the copy. */
  actions?: ReactNode
  /** Optional supporting content such as suggestion chips. */
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg'
  className?: string
}

/** Illustration-led placeholder for "nothing here yet" surfaces. */
export function EmptyState({
  icon: Icon = Inbox,
  title,
  description,
  actions,
  footer,
  size = 'md',
  className,
}: EmptyStateProps) {
  const iconBox = size === 'sm' ? 'size-16' : size === 'lg' ? 'size-32' : 'size-24'
  const iconSize = size === 'sm' ? 26 : size === 'lg' ? 52 : 38

  return (
    <motion.div
      initial={{ opacity: 0, y: 14 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.4, ease: 'easeOut' }}
      className={cn(
        'flex flex-col items-center justify-center px-6 py-12 text-center',
        className,
      )}
    >
      <div className="relative mb-6">
        <span
          className="absolute inset-0 rounded-full bg-brand/20 animate-pulse-ring"
          aria-hidden
        />
        <span
          className={cn(
            'relative grid place-items-center rounded-full bg-white/10 text-brand ring-1 ring-hairline-strong',
            iconBox,
          )}
        >
          <Icon size={iconSize} aria-hidden />
        </span>
      </div>

      <h3
        className={cn(
          'font-semibold text-white',
          size === 'lg' ? 'text-2xl' : 'text-xl',
        )}
      >
        {title}
      </h3>

      {description && (
        <p className="mt-3 max-w-lg text-md text-white/90">{description}</p>
      )}

      {actions && (
        <div className="mt-7 flex flex-wrap items-center justify-center gap-3">
          {actions}
        </div>
      )}

      {footer && <div className="mt-8 w-full">{footer}</div>}
    </motion.div>
  )
}
