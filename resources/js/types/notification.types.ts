export interface NotificationAction {
  readonly label: string
  readonly href: string
}

export interface AppNotification {
  readonly id: number
  /** Workflow origin, e.g. `takeoff-ready`, `review-completed`, `estimate-ready`. */
  readonly type: string
  /** Broad group `categoryFor()` derives from `type` — what "Filter by Type" filters on. */
  readonly category: string
  readonly title: string
  readonly detail: string
  /** Screen needing attention, when the notification points at one. Legacy single-action fallback. */
  readonly link: string | null
  /** Labelled destinations — one or more; falls back to a single "View" derived from `link`. */
  readonly actions: readonly NotificationAction[]
  /** ISO timestamp — formatted with date-fns at render time. */
  readonly timestamp: string
  readonly readAt: string | null
  readonly unread: boolean
}

export type NotificationTab = 'all' | 'unread' | 'read'

export interface NotificationCategoryOption {
  readonly value: string
  readonly label: string
}

export interface NotificationFilters {
  readonly tab: NotificationTab
  readonly category: string
}

export type NotificationTabCounts = Record<NotificationTab, number>
