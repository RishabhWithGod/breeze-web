import type { ReactNode } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  AlertTriangle,
  CheckCircle2,
  Info,
  X,
  XCircle,
  type LucideIcon,
} from 'lucide-react'
import type { Tone } from '@/types'
import { cn } from '@/utils'

type AlertTone = Extract<Tone, 'brand' | 'success' | 'warning' | 'danger' | 'info'>

const TONE_STYLES: Record<AlertTone, { wrapper: string; icon: string }> = {
  brand: { wrapper: 'border-l-brand bg-brand/10', icon: 'text-brand' },
  info: { wrapper: 'border-l-status-info bg-status-info/10', icon: 'text-status-info' },
  success: {
    wrapper: 'border-l-status-success bg-status-success/10',
    icon: 'text-status-success',
  },
  warning: {
    wrapper: 'border-l-status-warning bg-status-warning/10',
    icon: 'text-status-warning',
  },
  danger: {
    wrapper: 'border-l-status-danger bg-status-danger/15',
    icon: 'text-red-300',
  },
}

const TONE_ICONS: Record<AlertTone, LucideIcon> = {
  brand: Info,
  info: Info,
  success: CheckCircle2,
  warning: AlertTriangle,
  danger: XCircle,
}

export interface AlertProps {
  tone?: AlertTone
  title?: ReactNode
  icon?: LucideIcon
  onDismiss?: () => void
  className?: string
  children: ReactNode
}

/** Inline message block with a left accent rule, matching the reference notebox. */
export function Alert({
  tone = 'info',
  title,
  icon,
  onDismiss,
  className,
  children,
}: AlertProps) {
  const Icon = icon ?? TONE_ICONS[tone]
  const styles = TONE_STYLES[tone]

  return (
    <motion.div
      role="alert"
      initial={{ opacity: 0, y: -8 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -8 }}
      transition={{ duration: 0.22 }}
      className={cn(
        'flex items-start gap-3 rounded-panel border border-hairline border-l-4 p-4',
        styles.wrapper,
        className,
      )}
    >
      <Icon size={20} className={cn('mt-0.5 shrink-0', styles.icon)} aria-hidden />
      <div className="min-w-0 flex-1">
        {title && <p className="font-semibold text-white">{title}</p>}
        <div className="text-md text-white">{children}</div>
      </div>
      {onDismiss && (
        <button
          type="button"
          onClick={onDismiss}
          aria-label="Dismiss message"
          className="rounded-full p-1 text-white/85 transition-colors hover:bg-white/10 hover:text-white"
        >
          <X size={16} aria-hidden />
        </button>
      )}
    </motion.div>
  )
}

/** Convenience wrapper that animates presence for a nullable message. */
export function AlertSlot({
  message,
  tone = 'danger',
  onDismiss,
}: {
  message: string | null
  tone?: AlertTone
  onDismiss?: () => void
}) {
  return (
    <AnimatePresence initial={false}>
      {message && (
        <Alert key={message} tone={tone} {...(onDismiss ? { onDismiss } : {})}>
          {message}
        </Alert>
      )}
    </AnimatePresence>
  )
}
