import { useEffect, useRef } from 'react'
import { animate, motion, useInView, useMotionValue, useTransform } from 'framer-motion'
import { cn } from '@/utils'

export interface AnimatedCounterProps {
  /** Final value to count up to. */
  value: number
  prefix?: string
  suffix?: string
  decimals?: number
  /** Seconds. */
  duration?: number
  className?: string
}

/**
 * Counts up to `value` when it scrolls into view.
 *
 * Driven entirely by a Framer Motion value — the DOM text node is updated
 * outside React, so the animation costs zero re-renders.
 */
export function AnimatedCounter({
  value,
  prefix = '',
  suffix = '',
  decimals = 0,
  duration = 1.4,
  className,
}: AnimatedCounterProps) {
  const ref = useRef<HTMLSpanElement>(null)
  const isInView = useInView(ref, { once: true, amount: 0.4 })

  const count = useMotionValue(0)
  const formatted = useTransform(count, (latest) => {
    const rounded =
      decimals > 0 ? latest.toFixed(decimals) : Math.round(latest).toString()
    const [whole = '0', fraction] = rounded.split('.')
    const grouped = Number(whole).toLocaleString('en-US')
    return `${prefix}${fraction ? `${grouped}.${fraction}` : grouped}${suffix}`
  })

  useEffect(() => {
    if (!isInView) return undefined

    const controls = animate(count, value, { duration, ease: [0.16, 1, 0.3, 1] })
    return () => controls.stop()
  }, [isInView, value, duration, count])

  return (
    <motion.span ref={ref} className={cn('tabular-nums', className)}>
      {formatted}
    </motion.span>
  )
}
