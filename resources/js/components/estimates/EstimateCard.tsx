import { motion } from 'framer-motion'
import { Button, ButtonLink, StatusChip } from '@/components/common'
import { routeTo } from '@/constants'
import type { Estimate } from '@/types'
import {
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  cn,
  formatCurrency,
  formatDate,
} from '@/utils'

export interface EstimateCardProps {
  estimate: Estimate
  index?: number
  onDelete: (estimate: Estimate) => void
  className?: string
}

/**
 * Small-screen equivalent of an estimates table row — the same six fields and
 * both actions, so nothing hides behind a sideways scroll on a phone.
 */
export function EstimateCard({
  estimate,
  index = 0,
  onDelete,
  className,
}: EstimateCardProps) {
  return (
    <motion.li
      initial={{ opacity: 0, y: 10 }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true }}
      transition={{ duration: 0.3, delay: Math.min(index, 6) * 0.05 }}
      className={cn(
        'rounded-panel border border-hairline bg-white/4 p-4 transition-colors hover:border-brand/35',
        className,
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-bold text-white">{estimate.number}</p>
          <p className="mt-0.5 truncate text-sm text-white/60">{estimate.project}</p>
        </div>
        <StatusChip
          hideDot
          tone={ESTIMATE_STATUS_TONE[estimate.status]}
          label={ESTIMATE_STATUS_LABEL[estimate.status]}
          className="shrink-0 text-sm"
        />
      </div>

      <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        <div className="col-span-2">
          <dt className="text-white/45">Client</dt>
          <dd className="mt-0.5 truncate text-white/85">{estimate.client}</dd>
        </div>
        <div>
          <dt className="text-white/45">Date</dt>
          <dd className="mt-0.5 text-white/85">{formatDate(estimate.date)}</dd>
        </div>
        <div>
          <dt className="text-white/45">Amount</dt>
          <dd className="mt-0.5 tabular-nums text-white/85">
            {formatCurrency(estimate.amount, 2)}
          </dd>
        </div>
      </dl>

      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-hairline pt-3">
        <ButtonLink href={routeTo.estimate(estimate.id)} size="sm">
          View
        </ButtonLink>
        <Button
          variant="white"
          size="sm"
          className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
          onClick={() => onDelete(estimate)}
        >
          Delete
        </Button>
      </div>
    </motion.li>
  )
}
