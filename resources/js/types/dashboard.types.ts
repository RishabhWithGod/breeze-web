import type { FeedIconKey } from '@/lib/icons'

/** Pale tile fills behind list-row icons. */
export type TileTone = 'lilac' | 'butter'

/** Text run inside a feed line; `strong` renders it semibold. */
export interface TextSegment {
  readonly text: string
  readonly strong?: boolean
}

/**
 * A row in one of the icon-list panels (activity, notifications, schedule).
 * `icon` is a key rather than a component because these rows come from the
 * database — the client resolves it against the icon registry.
 */
export interface FeedItem {
  readonly id: number
  readonly segments: readonly TextSegment[]
  /** Second line — used by notifications and the schedule. */
  readonly detail: string | null
  readonly meta: string | null
  readonly icon: FeedIconKey
  readonly tile: TileTone
}

/** A headline tile on the dashboard. */
export interface DashboardSummary {
  readonly id: string
  /** Leading figure, rendered in the accent colour and counted up. */
  readonly value: number
  readonly label: string
  readonly icon: FeedIconKey
  readonly linkLabel: string
  readonly href: string
}

export interface MonthlyPoint {
  readonly month: string
  /** On-time completion rate, 0–100. Null when nothing completed that month. */
  readonly value: number | null
  /** Jobs completed that month — the denominator behind `value`. */
  readonly count: number
}
