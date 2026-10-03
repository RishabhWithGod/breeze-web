import { useEffect, useState } from 'react'
import { usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ShieldAlert, X } from 'lucide-react'
import type { SharedPageProps } from '@/types'

/**
 * Said once, on whichever screen someone is on, when they try something their role does not allow —
 * instead of an error page. Closes itself after a few seconds, or when dismissed.
 */
export function AccessNotice() {
  const { flash } = usePage<SharedPageProps>().props
  const message = flash.denied
  // Compared by the message text, not the flash object itself — a fresh
  // props object arrives with every response even when the session's own
  // flash data hasn't changed, which made a dismissed notice reappear on the
  // very next navigation while Laravel's own one-extra-request grace period
  // for flash data was still running out.
  const [closed, setClosed] = useState<string | null>(null)
  const visible = Boolean(message) && closed !== message

  useEffect(() => {
    if (!message) return

    const timer = window.setTimeout(() => setClosed(message), 8000)

    return () => window.clearTimeout(timer)
  }, [message])

  return (
    <AnimatePresence>
      {visible && (
        <motion.div
          key={message}
          role="alert"
          initial={{ opacity: 0, y: -12 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -12 }}
          className="fixed top-[calc(var(--spacing-navbar)+0.75rem)] right-4 z-50 flex max-w-md items-start gap-3 rounded-panel border border-status-warning/50 bg-navy-900/95 px-4 py-3 text-sm text-white shadow-2xl backdrop-blur"
        >
          <ShieldAlert size={20} aria-hidden className="mt-0.5 shrink-0 text-status-warning" />
          <p className="flex-1">{message}</p>
          <button
            type="button"
            aria-label="Dismiss"
            onClick={() => setClosed(message)}
            className="text-white/70 hover:text-white"
          >
            <X size={16} aria-hidden />
          </button>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
