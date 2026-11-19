import { Link } from '@inertiajs/react'
import { routeTo } from '@/constants'
import type { Estimate, EstimateStatus } from '@/types'
import { cn, formatCurrency, formatDate } from '@/utils'

/** The lanes, left to right, with the Breeze app's names for them and each lane's own top-edge colour. */
const LANES: readonly { readonly status: EstimateStatus; readonly label: string; readonly lane: string }[] = [
  { status: 'draft', label: 'Preparing', lane: 'bg-[#94a3b8]' },
  { status: 'sent', label: 'Submitted', lane: 'bg-linear-to-r from-[#f59e0b] to-[#fbbf24]' },
  { status: 'approved', label: 'Won', lane: 'bg-linear-to-r from-[#16a34a] to-[#4ade80]' },
  { status: 'rejected', label: 'Lost', lane: 'bg-linear-to-r from-[#dc2626] to-[#f87171]' },
]

export interface BidBoardProps {
  estimates: readonly Estimate[]
}

/**
 * Every estimate as a card in the lane of its status — the Breeze app's bid board. Cards open the
 * estimate; nothing on the board changes one.
 */
export function BidBoard({ estimates }: BidBoardProps) {
  return (
    <div className="grid grid-cols-[repeat(4,minmax(220px,1fr))] gap-3 overflow-x-auto pb-1">
      {LANES.map(({ status, label, lane }) => {
        const items = estimates.filter((estimate) => estimate.status === status)
        const total = items.reduce((sum, estimate) => sum + estimate.amount, 0)

        return (
          <section
            key={status}
            aria-label={label}
            className="relative min-h-52 overflow-hidden rounded-[18px] border border-hairline bg-[var(--surface-2)]/70 p-3"
          >
            <span aria-hidden className={cn('absolute inset-x-0 top-0 h-[3px]', lane)} />

            <h3 className="mx-1 mt-1 mb-3 flex justify-between text-xs leading-tight font-semibold tracking-[0.06em] text-[var(--text-2)] uppercase">
              <span>
                {label} · {items.length}
              </span>
              <span>{formatCurrency(total, 0)}</span>
            </h3>

            <div className="grid gap-2">
              {items.map((estimate) => (
                <Link
                  key={estimate.id}
                  href={routeTo.estimate(estimate.id)}
                  className="block rounded-xl border border-hairline bg-surface-glass px-3.5 py-3 shadow-[var(--card-shadow)] transition-[transform,box-shadow,border-color] duration-150 hover:-translate-y-0.5 hover:border-brand/45 hover:shadow-[0_16px_36px_-20px_rgb(47_91_255/0.5)]"
                >
                  <div className="flex items-baseline justify-between gap-2">
                    <b className="text-[13px] text-white">{estimate.number}</b>
                    <b className="font-mono text-[13px] font-medium text-white tabular-nums">
                      {formatCurrency(estimate.amount, 0)}
                    </b>
                  </div>
                  <p className="mt-2 truncate text-[13px] font-semibold text-white">{estimate.project ?? '—'}</p>
                  <p className="truncate text-[13px] text-[var(--text-3)]">{estimate.client}</p>
                  <p
                    className={cn(
                      'mt-2 text-[13px]',
                      status === 'rejected' ? 'text-status-danger' : 'text-[var(--text-3)]',
                    )}
                  >
                    {status === 'rejected' ? 'Rejected' : `Issued ${formatDate(estimate.date)}`}
                    {estimate.revision > 0 ? ` · Revision ${estimate.revision}` : ''}
                  </p>
                </Link>
              ))}

              {items.length === 0 && <p className="p-2 text-[13px] text-[var(--text-3)]">Nothing here.</p>}
            </div>
          </section>
        )
      })}
    </div>
  )
}
