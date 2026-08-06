export interface AppNotification {
  readonly id: number
  /** Workflow origin, e.g. `takeoff-ready`, `review-completed`, `estimate-ready`. */
  readonly type: string
  readonly title: string
  readonly detail: string
  /** Screen needing attention, when the notification points at one. */
  readonly link: string | null
  /** ISO timestamp — formatted with date-fns at render time. */
  readonly timestamp: string
  readonly unread: boolean
}
