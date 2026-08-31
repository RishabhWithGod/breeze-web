import { Link, router } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { ArrowRightLeft, CheckCircle2, ExternalLink, FilePlus2 } from 'lucide-react'
import { Button, ButtonLink, StatusChip } from '@/components/common'
import { routeTo } from '@/constants'
import type { JobEstimateSummary } from '@/types'
import {
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  formatCurrency,
  formatDate,
} from '@/utils'

export interface JobEstimatesPanelProps {
  jobId: number
  estimates: readonly JobEstimateSummary[]
}

/**
 * Estimates raised against this job (a real `estimates.job_id` relationship),
 * each convertible into a takeoff project.
 */
export function JobEstimatesPanel({ jobId, estimates }: JobEstimatesPanelProps) {
  const create = () => {
    router.post(routeTo.jobEstimates(jobId), {}, { preserveScroll: true })
  }

  const convert = (estimateId: number) => {
    router.post(
      routeTo.jobEstimateConvert(jobId, estimateId),
      {},
      { preserveScroll: true },
    )
  }

  return (
    <div>
      <div className="mb-5 flex justify-end">
        <Button size="sm" leftIcon={FilePlus2} onClick={create}>
          Create estimate
        </Button>
      </div>

      {estimates.length === 0 ? (
        <p className="text-md text-white/75">No estimates raised for this job yet.</p>
      ) : (
        <ul className="space-y-3">
          {estimates.map((estimate, index) => (
            <motion.li
              key={estimate.id}
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.25, delay: Math.min(index, 6) * 0.04 }}
              className="rounded-panel border border-hairline bg-white/4 p-4"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <Link
                    href={routeTo.estimate(estimate.id)}
                    className="font-mono text-md font-semibold text-white transition-colors hover:text-brand"
                  >
                    {estimate.number}
                  </Link>
                  <p className="mt-0.5 truncate text-sm text-white/85">
                    {estimate.project} · {formatDate(estimate.date)}
                  </p>
                </div>

                <div className="flex items-center gap-3">
                  <span className="text-md font-semibold tabular-nums text-white">
                    {formatCurrency(estimate.amount, 2)}
                  </span>
                  <StatusChip
                    hideDot
                    tone={ESTIMATE_STATUS_TONE[estimate.status]}
                    label={ESTIMATE_STATUS_LABEL[estimate.status]}
                  />
                </div>
              </div>

              <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-hairline pt-3">
                <ButtonLink
                  href={routeTo.estimate(estimate.id)}
                  variant="secondary"
                  size="sm"
                  leftIcon={ExternalLink}
                >
                  Open estimate
                </ButtonLink>
              </div>

              <div className="mt-3 border-t border-hairline pt-3">
                {estimate.isConverted ? (
                  <p className="flex items-center gap-2 text-sm text-status-success">
                    <CheckCircle2 size={15} aria-hidden />
                    Converted to client #{estimate.convertedProjectId}
                  </p>
                ) : (
                  <Button
                    variant="secondary"
                    size="sm"
                    leftIcon={ArrowRightLeft}
                    onClick={() => convert(estimate.id)}
                  >
                    Convert to client
                  </Button>
                )}
              </div>
            </motion.li>
          ))}
        </ul>
      )}
    </div>
  )
}
