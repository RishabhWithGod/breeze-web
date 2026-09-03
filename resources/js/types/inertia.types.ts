import type { AppNotification } from './notification.types'

/** The signed-in user, shared with every page by HandleInertiaRequests. */
export interface AuthUser {
  readonly id: number
  readonly name: string
  readonly email: string
  readonly role: string
  readonly initials: string
}

/** One-shot messages set with `->with('success', …)` on the server. */
export interface FlashMessages {
  readonly success: string | null
  readonly warning: string | null
  /** Id of a just-deleted job, so any screen can offer Undo. */
  readonly restoreJobId: number | null
  /** Plaintext 2FA recovery codes, present for exactly one response. */
  readonly recoveryCodes: readonly string[] | null
}

/**
 * The one timer a user may have running, visible from any page — never
 * trusted for its elapsed time alone; `startedAt`/`accumulatedSeconds` are
 * what a client re-derives the live count from between page loads.
 */
export interface ActiveTimer {
  readonly id: number
  readonly jobId: number
  readonly jobName: string
  readonly taskLabel: string | null
  readonly status: 'running' | 'paused'
  readonly startedAt: string
  readonly accumulatedSeconds: number
  readonly elapsedSeconds: number
  readonly billable: boolean
}

/** Props present on every page. */
/** Where to pick a half-finished takeoff back up. */
export interface ResumableTakeoff {
  readonly resumeUrl: string
  /** The step it is up to — "Review", "Estimate", "Job", "Tasks". */
  readonly stage: string
  readonly projectName: string
}

export interface SharedPageProps {
  readonly appName: string
  /** False when no geocoder is configured — the address field says so. */
  readonly addressLookupEnabled: boolean
  readonly auth: { readonly user: AuthUser | null }
  readonly notifications: readonly AppNotification[]
  readonly unreadNotificationCount: number
  readonly activeTimer: ActiveTimer | null
  /**
   * The takeoff this person is part-way through, when they are not on one of
   * its own screens. Null once it is finished, dismissed, or never started.
   */
  readonly takeoffFlow: ResumableTakeoff | null
  readonly flash: FlashMessages
  readonly errors: Record<string, string>
  /** Set by Inertia on every response. */
  readonly [key: string]: unknown
}

/** Laravel's paginator, as serialised by an API resource collection. */
export interface Paginated<T> {
  readonly data: readonly T[]
  readonly meta: {
    readonly current_page: number
    readonly last_page: number
    readonly per_page: number
    readonly total: number
    readonly from: number | null
    readonly to: number | null
  }
}
