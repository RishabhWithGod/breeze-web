/**
 * Scheduling — the crew calendar and the queue of work still to be booked.
 *
 * Mirrors JobScheduleResource and SchedulableJobResource. Dates that identify a
 * calendar cell are plain `YYYY-MM-DD` strings, not ISO timestamps: the grid keys
 * off them, and a timestamp would drag the browser's timezone into a decision the
 * server already made.
 */

export type JobPriority = 'high' | 'medium' | 'low'

export type ScheduleStatus = 'scheduled' | 'confirmed' | 'completed'

export type CalendarView = 'month' | 'week'

/** A crew member who can lead a shift. */
export interface CrewMember {
  readonly id: number
  readonly name: string
  readonly initials: string
  readonly role: string | null
}

/** One booked crew shift — the unit a calendar block draws. */
export interface JobShift {
  readonly id: number
  readonly jobId: number
  readonly jobName: string
  readonly client: string | null
  readonly location: string | null
  readonly jobType: string | null
  readonly priority: JobPriority
  /** What the shift was booked under: the job's crew, as it stood. */
  readonly crew: string
  /** The job's crew as it stands now. Null for a job with no team. */
  readonly teamName: string | null
  /** Who is on the work, read live off its tasks. */
  readonly foremen: readonly { readonly name: string; readonly initials: string }[]
  readonly supervisors: readonly { readonly name: string; readonly initials: string }[]
  /** `YYYY-MM-DD`; the grid buckets by this. */
  readonly date: string
  /** `HH:mm`, for form fields. */
  readonly startTime: string
  /** "8:00 AM", for display. */
  readonly startLabel: string
  readonly endLabel: string
  readonly durationHours: number
  readonly status: ScheduleStatus
  readonly notes: string | null
  readonly member: CrewMember | null
}

/** A job as the scheduling screens see it. */
export interface SchedulableJob {
  readonly id: number
  readonly name: string
  readonly client: string | null
  readonly location: string | null
  readonly jobType: string | null
  readonly status: string
  readonly priority: JobPriority
  readonly estimatedHours: number | null
  readonly requiredSkills: readonly string[]
  readonly value: number | null
  readonly startDate: string | null
  /** With `startDate`, the days the job actually runs. Either can be missing. */
  readonly endDate: string | null
  readonly createdAt: string | null
  /** The crew the job is handed to. Null for a job with no team yet. */
  readonly teamName: string | null
  /**
   * Who is already on the job. Both are assigned per task, so there can be
   * several of each — or none, before the work has been broken down.
   */
  readonly foremen: readonly { readonly name: string; readonly initials: string }[]
  readonly supervisors: readonly { readonly name: string; readonly initials: string }[]
  readonly shiftCount: number
}

/** One cell of the calendar grid, pre-labelled by the server. */
export interface CalendarDay {
  readonly date: string
  readonly dayOfMonth: number
  readonly weekday: string
  readonly label: string
  readonly isToday: boolean
  readonly isWeekend: boolean
  /** False for the neighbouring-month days a month view pads with. */
  readonly isCurrentPeriod: boolean
}

/** Hours booked against a crew member's capacity in the visible window. */
export interface CrewAvailability extends CrewMember {
  readonly hours: number
  readonly shifts: number
  readonly capacity: number
  /** Clamped to 100; read `isOverbooked` for the overflow. */
  readonly utilisation: number
  readonly isOverbooked: boolean
}

/** One of a crew member's shifts, as the availability screen lists it. */
export interface MemberAssignment {
  readonly id: number
  readonly jobId: number
  readonly jobName: string
  readonly client: string | null
  readonly crew: string
  readonly date: string
  readonly dayLabel: string
  readonly startLabel: string
  readonly endLabel: string
  readonly durationHours: number
  readonly status: ScheduleStatus
}

/** Hours booked per crew, for the part-to-whole breakdown. */
export interface CrewTotal {
  readonly crew: string
  readonly hours: number
  readonly shifts: number
  readonly members: number
  readonly jobs: number
  /** Percent of the booked total; the segments sum to 100. */
  readonly share: number
  readonly capacity: number
}

/** Two shifts that overlap in time for the same person on the same day. */
export interface ScheduleConflict {
  readonly id: string
  readonly date: string
  readonly dayLabel: string
  readonly member: CrewMember | null
  readonly overlapMinutes: number
  readonly first: ConflictSide
  readonly second: ConflictSide
}

export interface ConflictSide {
  readonly id: number
  readonly jobId: number
  readonly jobName: string
  readonly crew: string
  readonly startLabel: string
  readonly endLabel: string
}

/** A scheduling decision from the jobs' own activity trail. */
export interface SchedulingActivity {
  readonly id: number
  readonly type: 'scheduled' | 'unscheduled'
  readonly jobId: number
  readonly jobName: string
  readonly description: string
  readonly at: string | null
}

/** Headline figures for the visible window. */
export interface AvailabilitySummary {
  readonly members: number
  readonly bookedHours: number
  readonly capacityHours: number
  readonly utilisation: number
  readonly availableHours: number
  readonly overbooked: number
  readonly idle: number
  readonly shifts: number
  readonly unnamedShifts: number
}

export type AvailabilityTab = 'overview' | 'crews' | 'conflicts'

/* ------------------------------------------------------------- job schedule */

export type ScheduleState =
  | 'draft'
  | 'published'
  | 'in-progress'
  | 'on-hold'
  | 'completed'

export type TaskStatus =
  | 'pending'
  | 'ready'
  | 'in-progress'
  | 'blocked'
  | 'completed'
  | 'cancelled'
  | 'delayed'

export type TaskPriority = 'critical' | 'high' | 'medium' | 'low'

export type DependencyType = 'finish_to_start' | 'start_to_start' | 'finish_to_finish'

/** The plan a job runs to. */
export interface JobScheduleState {
  readonly id: number
  readonly startsOn: string | null
  readonly endsOn: string | null
  /** ISO day numbers, 1 = Monday. */
  readonly workingDays: readonly number[]
  readonly workStartTime: string
  readonly workEndTime: string
  readonly breakMinutes: number
  readonly hoursPerDay: number
  readonly timezone: string
  readonly holidays: readonly string[]
  readonly status: ScheduleState
  readonly progressPct: number
  readonly notes: string | null
  readonly publishedAt: string | null
  readonly durationWorkingDays: number
}

export interface TaskAssignment {
  readonly id: number
  readonly role: string
  readonly member: CrewMember | null
}

export interface TaskDependencyEdge {
  readonly id: number
  readonly dependsOnId: number
  readonly dependsOnTitle: string | null
  readonly type: DependencyType
  readonly typeLabel: string
  readonly lagDays: number
}

export interface ScheduleTask {
  readonly id: number
  readonly scheduleId: number
  readonly jobId: number
  readonly title: string
  readonly description: string | null
  readonly status: TaskStatus
  readonly priority: TaskPriority
  readonly category: string | null
  readonly estimatedHours: number | null
  readonly actualHours: number
  readonly startsOn: string | null
  readonly endsOn: string | null
  readonly baselineEndsOn: string | null
  readonly completionPct: number
  readonly position: number
  readonly isMilestone: boolean
  readonly notes: string | null
  readonly completedAt: string | null
  readonly isOverdue: boolean
  readonly daysLate: number
  readonly slippedDays: number
  readonly durationDays: number
  readonly assignments: readonly TaskAssignment[]
  readonly dependencies: readonly TaskDependencyEdge[]
  readonly commentCount: number
  readonly attachmentCount: number
}

/** Every figure the progress panel shows, all derived from the tasks. */
export interface ScheduleProgressState {
  readonly tasks: number
  readonly counted: number
  readonly completed: number
  readonly remaining: number
  readonly inProgress: number
  readonly blocked: number
  readonly delayed: number
  readonly cancelled: number
  readonly milestones: number
  readonly milestonesMet: number
  readonly taskPct: number
  readonly workPct: number
  readonly expectedPct: number
  readonly estimatedHours: number
  readonly actualHours: number
  readonly startsOn: string | null
  readonly endsOn: string | null
  readonly durationDays: number
  readonly elapsedDays: number
  readonly remainingDays: number
  readonly estimatedCompletion: string | null
  readonly slippedDays: number
  readonly isBehind: boolean
  readonly variancePct: number
}

/** One task as a bar on the timeline, positioned as a percentage of the window. */
export interface TimelineBar {
  readonly taskId: number
  readonly title: string
  readonly status: TaskStatus
  readonly category: string | null
  readonly isMilestone: boolean
  readonly completionPct: number
  readonly isOverdue: boolean
  readonly offsetPct: number
  readonly widthPct: number
  readonly startsOn: string
  readonly endsOn: string
}

export interface ScheduleTimelineState {
  readonly from: string | null
  readonly to: string | null
  readonly totalDays: number
  readonly bars: readonly TimelineBar[]
  readonly months: readonly { readonly label: string; readonly offsetPct: number }[]
  readonly todayOffsetPct?: number | null
}

export interface ScheduleCalendarDay {
  readonly date: string
  readonly dayOfMonth: number
  readonly weekday: string
  readonly label: string
  readonly isToday: boolean
  readonly isCurrentPeriod: boolean
  readonly isWorkingDay: boolean
  readonly isHoliday: boolean
  readonly items: readonly {
    readonly taskId: number
    readonly title: string
    readonly status: TaskStatus
    readonly category: string | null
    readonly isMilestone: boolean
    readonly isStart: boolean
    readonly isEnd: boolean
  }[]
}

export interface ScheduleCalendarState {
  readonly monthLabel: string
  readonly from: string
  readonly to: string
  readonly days: readonly ScheduleCalendarDay[]
}

export interface UpcomingTask {
  readonly id: number
  readonly title: string
  readonly status: TaskStatus
  readonly priority: TaskPriority
  readonly endsOn: string
  readonly dueLabel: string
  readonly daysAway: number
  readonly isMilestone: boolean
  readonly assignees: readonly string[]
}

export interface DelayedTask {
  readonly id: number
  readonly title: string
  readonly status: TaskStatus
  readonly endsOn: string | null
  readonly daysLate: number
  readonly slippedDays: number
  readonly completionPct: number
  readonly notes: string | null
  readonly blockedBy: readonly string[]
  readonly assignees: readonly string[]
}

export interface DependencyBreach {
  readonly taskId: number
  readonly taskTitle: string
  readonly dependsOnId: number
  readonly dependsOnTitle: string
  readonly type: DependencyType
  readonly typeLabel: string
  readonly lagDays: number
  readonly problem: string
}

export interface ScheduleDependencyState {
  readonly edges: readonly (TaskDependencyEdge & {
    readonly taskId: number
    readonly taskTitle: string
    readonly taskStatus: TaskStatus
  })[]
  readonly breaches: readonly DependencyBreach[]
  readonly blocked: Record<number, readonly string[]>
  readonly readyToStart: readonly number[]
}

/** A person on this job, with what they are carrying here and elsewhere. */
export interface ScheduleResource {
  readonly id: number
  readonly name: string
  readonly initials: string
  readonly title: string | null
  readonly roles: readonly string[]
  readonly taskCount: number
  readonly openCount: number
  readonly hours: number
  readonly lateCount: number
  readonly shiftsElsewhere: number
  readonly hoursElsewhere: number
}

export interface ScheduleMilestone {
  readonly id: number
  readonly title: string
  readonly status: TaskStatus
  readonly endsOn: string | null
  readonly dueLabel: string | null
  readonly isMet: boolean
  readonly isOverdue: boolean
  readonly daysLate: number
}

export interface ScheduleActivityEntry {
  readonly id: number
  readonly type: string
  readonly description: string
  readonly at: string | null
  readonly author: string | null
}

/** What this user's role is allowed to do. */
export interface ScheduleAbilities {
  readonly updateSchedule: boolean
  readonly createTask: boolean
  readonly reorder: boolean
  readonly assign: boolean
  readonly deleteTask: boolean
  readonly comment: boolean
}

/** Mirrors `JobScheduleController::show()` field-for-field — the whole per-job schedule screen in one payload. */
export interface JobScheduleProps {
  job: {
    readonly id: number
    readonly name: string
    readonly client: string | null
    readonly location: string | null
    readonly status: string
    readonly priority: TaskPriority
    readonly jobType: string | null
    readonly budget: number | null
    readonly foreman: { readonly name: string; readonly initials: string } | null
  }
  schedule: JobScheduleState
  tasks: readonly ScheduleTask[]
  progress: ScheduleProgressState
  timeline: ScheduleTimelineState
  calendar: ScheduleCalendarState
  crewShifts: readonly JobShift[]
  upcoming: readonly UpcomingTask[]
  delays: readonly DelayedTask[]
  dependencies: ScheduleDependencyState
  resources: readonly ScheduleResource[]
  milestones: readonly ScheduleMilestone[]
  criticalPath: readonly number[]
  activity: readonly ScheduleActivityEntry[]
  members: readonly CrewMember[]
  options: {
    readonly statuses: readonly TaskStatus[]
    readonly priorities: readonly TaskPriority[]
    readonly categories: readonly string[]
    readonly roles: readonly string[]
    readonly dependencyTypes: readonly DependencyType[]
    readonly scheduleStatuses: readonly ScheduleState[]
  }
  can: ScheduleAbilities
  today: string
}
