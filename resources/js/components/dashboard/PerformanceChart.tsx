import { useId } from 'react'
import { motion } from 'framer-motion'
import type { MonthlyPoint } from '@/types'
import { cn } from '@/utils'

/* Chart geometry, in viewBox units. The SVG carries only the bars — every
   label is real DOM text so it stays crisp and responsive at any width. */
const VIEW_WIDTH = 600
const VIEW_HEIGHT = 240
const BAR_WIDTH = 30
/** 4-unit radius on the data-end; the overhang below the baseline is clipped
    away so the foot of each bar stays square. */
const BAR_RADIUS = 4
const AXIS_MAX = 100
const AXIS_STEP = 10

export interface PerformanceChartProps {
  data: readonly MonthlyPoint[]
  /** Series name — shown in the legend key and used for accessible labelling. */
  seriesLabel: string
  className?: string
}

/**
 * Single-series column chart.
 *
 * Follows the house mark specs: capped bar thickness, a 4px rounded data-end
 * squared at the baseline, hairline recessive gridlines, no per-bar labels
 * (values live in the hover tooltip and the screen-reader table).
 */
export function PerformanceChart({
  data,
  seriesLabel,
  className,
}: PerformanceChartProps) {
  const clipId = useId()
  const ticks = Array.from(
    { length: AXIS_MAX / AXIS_STEP + 1 },
    (_, index) => AXIS_MAX - index * AXIS_STEP,
  )
  const slot = VIEW_WIDTH / data.length

  return (
    <figure className={cn('m-0', className)}>
      {/* Title + legend key, mirroring the reference panel. */}
      <figcaption className="mb-5 text-center">
        <p className="text-md font-semibold text-white">{seriesLabel}</p>
        <span className="mt-2 inline-flex items-center gap-2 text-sm text-white/90">
          <span className="h-3 w-6 rounded-xs bg-brand-deep" aria-hidden />
          {seriesLabel}
        </span>
      </figcaption>

      <div className="flex gap-3">
        {/* Y axis */}
        <ul className="flex h-52 flex-col justify-between text-right text-xs tabular-nums text-white/70 sm:h-60">
          {ticks.map((tick) => (
            <li key={tick} className="leading-none">
              {tick}
            </li>
          ))}
        </ul>

        <div className="min-w-0 flex-1">
          <div className="relative h-52 sm:h-60">
            {/* Gridlines — hairline, solid, recessive. */}
            <div
              aria-hidden
              className="absolute inset-0 flex flex-col justify-between"
            >
              {ticks.map((tick) => (
                <span key={tick} className="block border-t border-white/8" />
              ))}
            </div>

            <svg
              viewBox={`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`}
              preserveAspectRatio="none"
              role="img"
              aria-label={`${seriesLabel} by month, indexed 0 to ${AXIS_MAX}`}
              className="absolute inset-0 size-full overflow-visible"
            >
              <defs>
                <clipPath id={clipId}>
                  <rect x="0" y="0" width={VIEW_WIDTH} height={VIEW_HEIGHT} />
                </clipPath>
              </defs>

              <g clipPath={`url(#${clipId})`}>
                {data.map((point, index) => {
                  const height = (point.value / AXIS_MAX) * VIEW_HEIGHT
                  const x = index * slot + (slot - BAR_WIDTH) / 2

                  return (
                    <g key={point.month} className="group/bar">
                      {/* Full-height hit target keeps hovering easy. */}
                      <rect
                        x={index * slot}
                        y={0}
                        width={slot}
                        height={VIEW_HEIGHT}
                        className="fill-transparent"
                      />
                      <motion.rect
                        x={x}
                        width={BAR_WIDTH}
                        rx={BAR_RADIUS}
                        initial={{ height: 0, y: VIEW_HEIGHT }}
                        whileInView={{
                          height: height + BAR_RADIUS,
                          y: VIEW_HEIGHT - height,
                        }}
                        viewport={{ once: true, amount: 0.3 }}
                        transition={{
                          duration: 0.8,
                          delay: index * 0.05,
                          ease: [0.16, 1, 0.3, 1],
                        }}
                        className="fill-brand-deep transition-colors group-hover/bar:fill-brand"
                      >
                        <title>{`${point.month}: ${point.value}`}</title>
                      </motion.rect>
                    </g>
                  )
                })}
              </g>
            </svg>
          </div>

          {/* X axis — one label per bar, stepped down a size on narrow screens
              so all twelve stay aligned with their columns. */}
          <ul className="mt-2 flex text-center text-2xs text-white/70 sm:text-xs">
            {data.map((point) => (
              <li key={point.month} className="min-w-0 flex-1 truncate">
                {point.month}
              </li>
            ))}
          </ul>
        </div>
      </div>

      {/* Values stay reachable without hovering. */}
      <table className="sr-only">
        <caption>{seriesLabel} by month</caption>
        <thead>
          <tr>
            <th scope="col">Month</th>
            <th scope="col">{seriesLabel}</th>
          </tr>
        </thead>
        <tbody>
          {data.map((point) => (
            <tr key={point.month}>
              <th scope="row">{point.month}</th>
              <td>{point.value}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  )
}
