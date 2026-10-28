/** Whether someone can be given work today, from their approved leave. */
export type LeaveState = 'on_leave' | 'upcoming' | 'pending' | 'available'

/** One person's availability, worked out by the server from today's date. */
export interface LeaveStatus {
  readonly state: LeaveState
  /** On leave: days until they are available again, today included. Drops by one each day. */
  readonly daysLeft: number | null
  /** The first day they are available again (`YYYY-MM-DD`). */
  readonly availableOn: string | null
  /** Leave coming up: how many days until it starts. */
  readonly startsIn: number | null
  readonly startsOn: string | null
  readonly endsOn: string | null
  readonly type: string | null
  readonly typeLabel: string | null
  /** Requests still waiting for a manager. */
  readonly pending: number
}

export type LeaveRequestStatus = 'pending' | 'approved' | 'rejected' | 'cancelled'

export interface LeaveMember {
  readonly id: number | null
  readonly name: string
  readonly initials: string
  readonly roleLabel: string
  readonly team: string | null
  readonly inactive: boolean
}

export interface LeaveRequestRow {
  readonly id: number
  readonly member: LeaveMember | null
  readonly type: string
  readonly typeLabel: string
  readonly startDate: string
  readonly endDate: string
  readonly days: number
  readonly reason: string | null
  readonly status: LeaveRequestStatus
  readonly decisionNote: string | null
  readonly decidedAt: string | null
  readonly requestedAt: string | null
  /** Scheduled crew shifts that fall inside these days. */
  readonly shiftsAffected: number
  /** Approved, and today is one of its days. */
  readonly underway: boolean
  readonly canRevoke: boolean
}
