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

/**
 * The Breeze app's alert: a soft tint of the tone with its own darker ink, 12px corners, no border and no
 * side bar. Everything inside — the title, the text, links — takes the tone's ink.
 */
const TONE_CLASSES: Record<AlertTone, string> = {
  brand: 'bg-[var(--brand-bg)] text-brand',
  info: 'bg-[var(--info-bg)] text-[var(--info-text)]',
  success: 'bg-[var(--ok-bg)] text-[var(--ok-text)]',
  warning: 'bg-[var(--warn-bg)] text-[var(--warn-text)]',
  danger: 'bg-[var(--danger-bg)] text-[var(--danger-text)]',
}

/** The same look for a notice that is laid out by hand rather than with `<Alert>`. */
export function noticeClasses(tone: AlertTone = 'info', className?: string): string {
  return cn('rounded-xl text-[13.5px]', TONE_CLASSES[tone], className)
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

/** Inline message block. */
export function Alert({
  tone = 'info',
  title,
  icon,
  onDismiss,
  className,
  children,
}: AlertProps) {
  const Icon = icon ?? TONE_ICONS[tone]

  return (
    <motion.div
      role="alert"
      initial={{ opacity: 0, y: -8 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -8 }}
      transition={{ duration: 0.22 }}
      className={cn('flex items-start gap-2.5 px-3.5 py-3', noticeClasses(tone), className)}
    >
      <Icon size={16} className="mt-[3px] shrink-0" aria-hidden />
      <div className="min-w-0 flex-1">
        {title && <b className="block font-bold">{title}</b>}
        <div>{children}</div>
      </div>
      {onDismiss && (
        <button
          type="button"
          onClick={onDismiss}
          aria-label="Dismiss message"
          className="-mr-1 shrink-0 rounded-full p-1 opacity-70 transition-opacity hover:opacity-100"
        >
          <X size={15} aria-hidden />
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
