import { Square } from 'lucide-react'
import type { PaymentProcessor } from '@/types'
import { cn } from '@/utils'

const SIZES = {
  sm: { box: 'size-9 rounded-lg', text: 'text-sm', icon: 16 },
  md: { box: 'size-12 rounded-xl', text: 'text-lg', icon: 20 },
} as const

/**
 * Each processor's own brand color, so the connections list reads at a
 * glance instead of every row looking identical. Stripe and PayPal are
 * rendered as their initial in a bold sans rather than a traced wordmark —
 * safe to reproduce in code without the actual logo artwork, still
 * instantly recognizable next to the printed name. Square is drawn as its
 * literal namesake shape instead, since that mark has no letterform to
 * borrow.
 */
const PROCESSOR_STYLE: Record<
  PaymentProcessor['key'],
  { bg: string; fg: string; render: (size: keyof typeof SIZES) => React.ReactNode }
> = {
  stripe: {
    bg: '#635BFF',
    fg: '#FFFFFF',
    render: (size) => <span className={cn('font-bold', SIZES[size].text)}>S</span>,
  },
  paypal: {
    bg: '#0F1F58',
    fg: '#00A3E0',
    render: (size) => <span className={cn('font-bold', SIZES[size].text)}>P</span>,
  },
  square: {
    bg: '#0A0A0A',
    fg: '#FFFFFF',
    render: (size) => <Square size={SIZES[size].icon} strokeWidth={2.5} aria-hidden />,
  },
}

export interface ProcessorMarkProps {
  processorKey: PaymentProcessor['key']
  size?: keyof typeof SIZES
  className?: string
}

/** Brand-colored badge identifying a payment processor at a glance. */
export function ProcessorMark({ processorKey, size = 'md', className }: ProcessorMarkProps) {
  const style = PROCESSOR_STYLE[processorKey]

  return (
    <span
      aria-hidden
      className={cn('inline-grid shrink-0 place-items-center shadow-sm', SIZES[size].box, className)}
      style={{ backgroundColor: style.bg, color: style.fg }}
    >
      {style.render(size)}
    </span>
  )
}
