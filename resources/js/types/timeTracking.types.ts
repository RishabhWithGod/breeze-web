/**
 * Time Tracking — timer, entries, approval workflow, week view and reports.
 *
 * Mirrors `TimeEntryResource`/`TimerSessionResource`. Dates that identify a
 * day are plain `YYYY-MM-DD` strings, the same convention `scheduling.types`
 * uses, so a calendar grid keys off them without a timezone in the way.
 */

export type TimeEntryStatus = 'draft' | 'submitted' | 'approved' | 'rejected' | 'locked'

export type TimeEntrySource = 'manual' | 'timer'

export interface TimeEntryJobRef {
  readonly id: number
  readonly name: string
  readonly client: string | null
  readonly status: string
}

export interface TimeEntryTaskRef {
  readonly id: number
  readonly title: string
}

export interface TimeEntryPersonRef {
  readonly id: number
  readonly name: string
  readonly initials?: string
  readonly role: string | null
}

/** One block of time worked — the row a list, a week grid, or a report reads. */
export interface TimeEntry {
  readonly id: number
  readonly job: TimeEntryJobRef | null
  readonly jobTask: TimeEntryTaskRef | null
  readonly taskLabel: string | null
  readonly user: TimeEntryPersonRef | null
  readonly teamMember: TimeEntryPersonRef | null
  readonly date: string
  readonly startTime: string | null
  readonly endTime: string | null
  readonly breakMinutes: number
  readonly hours: number
  readonly regularHours: number
  readonly overtimeHours: number
  readonly description: string | null
  readonly billable: boolean
  readonly source: TimeEntrySource
  readonly status: TimeEntryStatus
  readonly submittedAt: string | null
  readonly approvedAt: string | null
  readonly rejectedAt: string | null
  readonly approver: string | null
  readonly rejecter: string | null
  readonly rejectionReason: string | null
  readonly isEditable: boolean
  readonly isLocked: boolean
  readonly isCorrection: boolean
  /** Only present for whoever is allowed to see job costs. */
  readonly laborCost: number | null
  readonly billableAmount: number | null
  readonly billableRate: number | null
  readonly costRate: number | null
}

/** One row of a time entry's audit trail — created/submitted/approved/etc. */
export interface TimeEntryActivity {
  readonly id: number
  readonly type: string
  readonly description: string
  readonly actor: string | null
  readonly timestamp: string
}

/** The Time Entry detail screen's "Electrician Information" card. */
export interface TimeEntryEmployeeDetail {
  readonly name: string
  readonly initials: string | null
  readonly role: string | null
  readonly email: string | null
  /** Only present for whoever is allowed to see job costs. */
  readonly billableRate: number | null
  readonly costRate: number | null
}

/** The Time Entry detail screen's "Job Information" card. */
export interface TimeEntryJobDetail {
  readonly id: number
  readonly name: string
  readonly client: string | null
  readonly status: string
  readonly location: string | null
  readonly jobType: string | null
  readonly foreman: string | null
  readonly startDate: string | null
  readonly endDate: string | null
}

/** The Time Entry detail screen's "Task Details" card. */
export interface TimeEntryTaskDetail {
  readonly id: number
  readonly title: string
  readonly description: string | null
  readonly category: string | null
  readonly status: string
  readonly priority: string | null
  readonly startsOn: string | null
  readonly endsOn: string | null
  readonly estimatedHours: number | null
  readonly actualHours: number
  readonly assignees: readonly { readonly id: number; readonly name: string; readonly role: string | null }[]
}

/** This entry's electrician set against the job's own totals. */
export interface TimeEntryJobTimeSummary {
  readonly thisEntryHours: number
  readonly approvedJobHours: number
  readonly employeeJobHours: number
  readonly employeeJobBillableHours: number
}

/** GPS job-site presence — the mobile app's check-in/check-out feature.
 *  Distinct from a [TimeEntry]: no task, no approval workflow, just "was
 *  this technician at the site, and for how long." */
export interface AttendanceRow {
  readonly id: number
  readonly date: string
  readonly employee: string
  readonly employeeRole: string | null
  readonly job: { readonly id: number; readonly name: string } | null
  readonly status: 'checkedIn' | 'checkedOut'
  readonly checkInAt: string | null
  readonly checkOutAt: string | null
  readonly checkInMethod: 'manual' | 'automatic' | 'photo' | null
  readonly checkOutMethod: 'manual' | 'automatic' | 'photo' | null
  readonly checkInDistanceMeters: number | null
  readonly checkOutDistanceMeters: number | null
  readonly hours: number
  readonly photoUrl: string | null
}

/** One side of a GPS check-in/check-out cycle — the detail screen's
 *  "Check In"/"Check Out" cards read the same shape for either. */
export interface AttendanceEventDetail {
  readonly at: string | null
  readonly method: 'manual' | 'automatic' | 'photo' | null
  readonly accuracyMeters: number | null
  readonly distanceMeters: number | null
  readonly lat: number | null
  readonly lng: number | null
}

/** The Attendance detail screen — a single [AttendanceRow], in full: every
 *  field `JobAttendance` carries, not just the list's summary columns. */
export interface AttendanceDetail {
  readonly id: number
  readonly date: string
  readonly status: 'checkedIn' | 'checkedOut'
  readonly employee: { readonly name: string }
  readonly job: { readonly id: number; readonly name: string; readonly client: string | null; readonly status: string } | null
  readonly hours: number
  readonly bankedSeconds: number
  readonly checkIn: AttendanceEventDetail & { readonly photoUrl: string | null }
  readonly checkOut: AttendanceEventDetail
}

/** The Time Entry detail screen's "Correction" link — enough to identify and
 *  jump to the other entry on either side of a correction (see
 *  `TimeEntry.corrects_id`). */
export interface TimeEntryCorrectionRef {
  readonly id: number
  readonly date: string
  readonly employee: string
  readonly hours: number
  readonly status: TimeEntryStatus
}

/** One row of the detail screen's "Related Entries" table. */
export interface TimeEntryRelatedRow {
  readonly id: number
  readonly date: string
  readonly employee: string
  readonly task: string
  readonly startTime: string | null
  readonly endTime: string | null
  readonly hours: number
  readonly status: TimeEntryStatus
}

/** What the signed-in user may do to this specific entry, computed server-side. */
export interface TimeEntryActionAbilities {
  readonly update: boolean
  readonly delete: boolean
  readonly submit: boolean
  readonly approve: boolean
  readonly reject: boolean
  readonly viewJobCosts: boolean
}

/** The one active/paused timer a user may have running. */
export interface TimerSessionState {
  readonly id: number
  readonly job: { readonly id: number; readonly name: string } | null
  readonly jobTask: TimeEntryTaskRef | null
  readonly taskLabel: string | null
  readonly description: string | null
  readonly startedAt: string
  readonly status: 'running' | 'paused'
  readonly billable: boolean
  /** Recomputed server-side on every request — never trusted from a prior response. */
  readonly elapsedSeconds: number
}

/** What this user's role is allowed to do, computed server-side. */
export interface TimeTrackingAbilities {
  readonly viewCrew: boolean
  readonly approve: boolean
  readonly viewJobCosts: boolean
  readonly viewReports: boolean
  readonly manageSettings: boolean
}

export interface TimeTrackingSummary {
  readonly todayHours: number
  readonly weekHours: number
  readonly billableHours: number
  readonly overtimeHours: number
  readonly pendingApprovalHours: number
  readonly laborCost: number | null
}

export interface TimeTrackingJobOption {
  readonly id: number
  readonly name: string
  readonly client: string | null
  readonly status: string
}

export interface TimeTrackingTaskOption {
  readonly id: number
  readonly title: string
  readonly estimatedHours: number | null
  readonly actualHours: number
  readonly status: string
}

/** One day of the weekly timesheet grid. */
export interface TimesheetDay {
  readonly date: string
  readonly label: string
  readonly isToday: boolean
  readonly isWeekend: boolean
  readonly regular: number
  readonly overtime: number
  readonly total: number
  readonly billable: number
  readonly entryCount: number
}

export interface TimesheetWeek {
  readonly weekStart: string
  readonly weekEnd: string
  readonly days: readonly TimesheetDay[]
  readonly totals: {
    readonly regular: number
    readonly overtime: number
    readonly total: number
    readonly billable: number
  }
}

/** The job-level labor read model — estimated vs actual, cost, variance. */
export interface JobLaborSummary {
  readonly estimatedHours: number
  readonly actualHours: number
  readonly remainingHours: number
  readonly billableHours: number
  readonly overtimeHours: number
  readonly laborCost: number | null
  readonly billableAmount: number | null
  readonly pendingApprovalHours: number
  readonly estimateLaborHours: number | null
  readonly estimateVarianceHours: number | null
}

export interface TimeTrackingReportRow {
  readonly id: number
  readonly label: string
  readonly hours: number
  readonly overtime?: number
  readonly cost?: number
}

export interface EstimatedVsActualRow extends JobLaborSummary {
  readonly job: string
  readonly jobId: number
}

export interface TimeTrackingReportsData {
  readonly range: { readonly from: string; readonly to: string }
  readonly byJob: readonly TimeTrackingReportRow[]
  readonly byEmployee: readonly TimeTrackingReportRow[]
  readonly byTask: readonly TimeTrackingReportRow[]
  readonly billableSplit: { readonly billable: number; readonly nonBillable: number }
  readonly overtimeTotal: number
  readonly laborCostTotal: number | null
  readonly estimatedVsActual: readonly EstimatedVsActualRow[]
  readonly canViewCosts: boolean
}

export interface TimeTrackingSettingsState {
  readonly regular_daily_hours: number
  readonly regular_weekly_hours: number
  readonly overtime_multiplier: number
  readonly weekend_overtime: boolean
  readonly holiday_overtime: boolean
  readonly holiday_dates: readonly string[] | null
  readonly default_billable_rate: number | null
  readonly default_cost_rate: number | null
  readonly timezone: string
}
