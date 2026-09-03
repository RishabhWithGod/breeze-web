import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { ExternalLink, PencilLine } from 'lucide-react'
import { ButtonLink, StatusChip } from '@/components/common'
import { routeTo } from '@/constants'
import type { JobEstimateSummary } from '@/types'
import {
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  formatCurrency,
  formatDate,
} from '@/utils'

export interface JobEstimatesPanelProps {
  /** Carried into each link so Back from an estimate comes back to this job. */
  jobId: number
  estimates: readonly JobEstimateSummary[]
}

/**
 * The estimates raised against this job — a real `estimates.job_id`
 * relationship.
 *
 * A list to read and open, nothing more. Raising an estimate and turning one
 * into a client are decisions made where those things belong, not as buttons
 * beside a job's summary of them.
 */
export function JobEstimatesPanel({ jobId, estimates }: JobEstimatesPanelProps) {
  return (
    <div>
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
                    href={routeTo.estimateFromJob(estimate.id, jobId)}
                    className="font-mono text-md font-semibold text-white transition-colors hover:text-brand"
                  >
                    {estimate.number}
                  </Link>
                  {/* The client is the job's own, so only the date adds anything. */}
                  <p className="mt-0.5 truncate text-sm text-white/85">
                    {formatDate(estimate.date)}
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
                  href={routeTo.estimateFromJob(estimate.id, jobId)}
                  variant="secondary"
                  size="sm"
                  leftIcon={ExternalLink}
                >
                  Open
                </ButtonLink>
                <ButtonLink
                  href={routeTo.estimateEditFromJob(estimate.id, jobId)}
                  variant="ghost"
                  size="sm"
                  leftIcon={PencilLine}
                >
                  Edit
                </ButtonLink>
              </div>
            </motion.li>
          ))}
        </ul>
      )}
    </div>
  )
}
