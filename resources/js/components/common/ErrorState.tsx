import type { ReactNode } from 'react'
import { motion } from 'framer-motion'
import { AlertOctagon, type LucideIcon } from 'lucide-react'
import { cn } from '@/utils'

export interface ErrorStateProps {
  icon?: LucideIcon
  /** Short machine-ish code shown above the title, e.g. "ERR_UPLOAD_413". */
  code?: string
  title: string
  description?: ReactNode
  actions?: ReactNode
  /** Collapsible technical detail block. */
  details?: string
  className?: string
}

/** Failure surface used for route errors and failed takeoff runs. */
export function ErrorState({
  icon: Icon = AlertOctagon,
  code,
  title,
  description,
  actions,
  details,
  className,
}: ErrorStateProps) {
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
      <span className="relative mb-6 grid size-24 place-items-center rounded-full bg-status-danger/15 text-red-300 ring-1 ring-status-danger/40">
        <span
          className="absolute inset-0 rounded-full bg-status-danger/25 animate-pulse-ring"
          aria-hidden
        />
        <Icon size={40} aria-hidden />
      </span>

      {code && (
        <p className="mb-2 font-mono text-xs tracking-[0.2em] text-red-300/80 uppercase">
          {code}
        </p>
      )}

      <h3 className="text-2xl font-semibold text-white">{title}</h3>

      {description && (
        <p className="mt-3 max-w-xl text-md text-white/90">{description}</p>
      )}

      {actions && (
        <div className="mt-7 flex flex-wrap items-center justify-center gap-3">
          {actions}
        </div>
      )}

      {details && (
        <details className="mt-8 w-full max-w-2xl text-left">
          <summary className="cursor-pointer text-sm text-white/80 transition-colors hover:text-brand">
            Technical details
          </summary>
          <pre className="mt-3 overflow-x-auto rounded-panel border border-hairline bg-navy-950/50 p-4 font-mono text-xs text-white/90">
            {details}
          </pre>
        </details>
      )}
    </motion.div>
  )
}
