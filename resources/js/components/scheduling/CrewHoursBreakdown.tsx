import { useState } from 'react'
import type { CrewTotal } from '@/types'
import { cn } from '@/utils'

/**
 * Categorical slots, in fixed order, taken from the validated palette's dark
 * column. Assigned by position and never cycled: a crew keeps its colour when
 * another crew's hours change, and a fourth crew folds into "Other" rather than
 * inventing a hue.
 *
 * Validated against this product's card surface (#101b38) — lightness band,
 * chroma floor, adjacent CVD separation (worst ΔE 9.4), normal-vision floor
 * (worst ΔE 26.5) and 3:1 contrast all pass.
 */
const SERIES = ['#3987e5', '#d95926', '#199e70'] as const
const OTHER = '#8faab2'

/** Past three crews the tail folds together rather than taking a generated hue. */
const MAX_SERIES = SERIES.length

export interface CrewHoursBreakdownProps {
  totals: readonly CrewTotal[]
  className?: string
}

interface Segment {
  key: string
  hours: number
  share: number
  shifts: number
  colour: string
}

/**
 * Booked hours split by crew.
 *
 * A stacked bar rather than a donut: this is part-to-whole, and the reader's real
 * question is "is one crew carrying the week", which is a length comparison. Slices
 * of a circle make that comparison harder, and two crews within a few hours of each
 * other become indistinguishable.
 *
 * Every segment is direct-labelled beneath the bar, so identity never rests on
 * colour alone, and the same numbers are available as a table to screen readers.
 */
export function CrewHoursBreakdown({ totals, className }: CrewHoursBreakdownProps) {
  const [hovered, setHovered] = useState<string | null>(null)

  const total = totals.reduce((sum, row) => sum + row.hours, 0)

  if (total <= 0) {
    return (
      <p className={cn('text-md text-white/80', className)}>
        Nothing booked in this period, so there are no hours to split.
      </p>
    )
  }

  const leading = totals.slice(0, MAX_SERIES)
  const tail = totals.slice(MAX_SERIES)

  const segments: Segment[] = leading.map((row, index) => ({
    key: row.crew,
    hours: row.hours,
    share: row.share,
    shifts: row.shifts,
    colour: SERIES[index] ?? OTHER,
  }))

  if (tail.length > 0) {
    segments.push({
      key: `Other (${tail.length})`,
      hours: tail.reduce((sum, row) => sum + row.hours, 0),
      share: tail.reduce((sum, row) => sum + row.share, 0),
      shifts: tail.reduce((sum, row) => sum + row.shifts, 0),
      colour: OTHER,
    })
  }

  return (
    <figure className={cn('m-0', className)}>
      <figcaption className="sr-only">
        Booked crew hours by crew, as a share of {total} hours.
      </figcaption>

      {/* The bar. A 2px surface gap separates the fills, so adjacent segments
          never read as one block. */}
      <div className="flex h-7 w-full gap-0.5 overflow-hidden rounded-panel">
        {segments.map((segment) => (
          <div
            key={segment.key}
            title={`${segment.key}: ${segment.hours} h (${segment.share}%)`}
            onMouseEnter={() => setHovered(segment.key)}
            onMouseLeave={() => setHovered(null)}
            onFocus={() => setHovered(segment.key)}
            onBlur={() => setHovered(null)}
            tabIndex={0}
            className={cn(
              'h-full min-w-1 rounded-xs transition-opacity duration-200 focus-visible:outline-none',
              hovered !== null && hovered !== segment.key && 'opacity-45',
            )}
            style={{ width: `${segment.share}%`, backgroundColor: segment.colour }}
          />
        ))}
      </div>

      {/* Legend and direct labels in one: a colour chip beside real text, so the
          numbers never wear the series colour. */}
      <ul className="mt-4 space-y-2.5">
        {segments.map((segment) => (
          <li
            key={segment.key}
            onMouseEnter={() => setHovered(segment.key)}
            onMouseLeave={() => setHovered(null)}
            className={cn(
              'flex items-center justify-between gap-3 text-md transition-opacity duration-200',
              hovered !== null && hovered !== segment.key && 'opacity-55',
            )}
          >
            <span className="flex min-w-0 items-center gap-2.5">
              <span
                aria-hidden
                className="size-3 shrink-0 rounded-xs"
                style={{ backgroundColor: segment.colour }}
              />
              <span className="truncate text-white">{segment.key}</span>
            </span>
            <span className="shrink-0 tabular-nums text-white/85">
              <span className="font-semibold text-white">{segment.hours} h</span>
              <span className="ml-2">{segment.share}%</span>
            </span>
          </li>
        ))}
      </ul>

      {/* The same figures as a table, for anyone who cannot use the bar. */}
      <table className="sr-only">
        <caption>Booked crew hours by crew</caption>
        <thead>
          <tr>
            <th scope="col">Crew</th>
            <th scope="col">Hours</th>
            <th scope="col">Share</th>
            <th scope="col">Shifts</th>
          </tr>
        </thead>
        <tbody>
          {segments.map((segment) => (
            <tr key={segment.key}>
              <th scope="row">{segment.key}</th>
              <td>{segment.hours}</td>
              <td>{segment.share}%</td>
              <td>{segment.shifts}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  )
}
