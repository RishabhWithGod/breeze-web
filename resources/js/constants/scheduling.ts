import type {
  DependencyType,
  JobPriority,
  ScheduleState,
  ScheduleStatus,
  SelectOption,
  TaskPriority,
  TaskStatus,
  Tone,
} from '@/types'

/** Tabs over the unassigned queue. Mirrors Job::TYPES plus "all". */
export const SCHEDULING_TYPE_FILTERS = [
  { label: 'All Jobs', value: 'all' },
  { label: 'Residential', value: 'residential' },
  { label: 'Commercial', value: 'commercial' },
  { label: 'Industrial', value: 'industrial' },
] as const

export type SchedulingTypeFilter = (typeof SCHEDULING_TYPE_FILTERS)[number]['value']

/** Mirrors Job::SCHEDULING_SORTS. */
export const SCHEDULING_SORT_OPTIONS: readonly SelectOption[] = [
  { label: 'Date (Latest First)', value: 'start-desc' },
  { label: 'Priority (High to Low)', value: 'priority-desc' },
  { label: 'Priority (Low to High)', value: 'priority-asc' },
  { label: 'Estimated Hours (Most)', value: 'hours-desc' },
  { label: 'Estimated Hours (Fewest)', value: 'hours-asc' },
  { label: 'Value (Highest)', value: 'value-desc' },
  { label: 'Recently Created', value: 'created-desc' },
  { label: 'Name (A–Z)', value: 'name-asc' },
]

export type SchedulingSort = 'start-desc' | 'priority-desc' | 'priority-asc' | 'hours-desc' | 'hours-asc' | 'value-desc' | 'created-desc' | 'name-asc'

/** Long form, used on the queue rows: "High Priority". */
export const PRIORITY_LABEL: Record<JobPriority, string> = {
  high: 'High Priority',
  medium: 'Medium Priority',
  low: 'Low Priority',
}

/** Short form, used on the calendar's compact cards. */
export const PRIORITY_SHORT_LABEL: Record<JobPriority, string> = {
  high: 'Priority',
  medium: 'Standard',
  low: 'Low',
}

export const PRIORITY_TONE: Record<JobPriority, Tone> = {
  high: 'danger',
  medium: 'warning',
  low: 'success',
}

/**
 * The stripe down the left of a calendar block, keyed by priority.
 *
 * A colour rather than a label because the grid has no room for one — the block's
 * own tooltip and the queue rows carry the words.
 */
export const PRIORITY_STRIPE: Record<JobPriority, string> = {
  high: 'bg-status-warning',
  medium: 'bg-brand',
  low: 'bg-status-success',
}

export const SCHEDULE_STATUS_LABEL: Record<ScheduleStatus, string> = {
  scheduled: 'Scheduled',
  confirmed: 'Confirmed',
  completed: 'Completed',
}

export const SCHEDULE_STATUS_TONE: Record<ScheduleStatus, Tone> = {
  scheduled: 'info',
  confirmed: 'success',
  completed: 'neutral',
}

/** Start times offered by the assign form — the working day in half-hours. */
export const SHIFT_START_OPTIONS: readonly SelectOption[] = Array.from(
  { length: 21 },
  (_, index) => {
    const minutes = 6 * 60 + index * 30
    const hour24 = Math.floor(minutes / 60)
    const minute = minutes % 60
    const suffix = hour24 < 12 ? 'AM' : 'PM'
    const hour12 = hour24 % 12 === 0 ? 12 : hour24 % 12
    const pad = (value: number) => String(value).padStart(2, '0')

    return {
      label: `${hour12}:${pad(minute)} ${suffix}`,
      value: `${pad(hour24)}:${pad(minute)}`,
    }
  },
)

/** Shift lengths a crew is actually booked for. */
export const SHIFT_DURATION_OPTIONS: readonly SelectOption[] = [
  { label: '2 hours', value: '2' },
  { label: '4 hours (half day)', value: '4' },
  { label: '6 hours', value: '6' },
  { label: '8 hours (full day)', value: '8' },
  { label: '10 hours', value: '10' },
  { label: '12 hours', value: '12' },
]

/** Column headings for the grid, Sunday-first to match the server's window. */
export const WEEKDAY_HEADINGS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const

/* ------------------------------------------------------------- job schedule */


export const TASK_STATUS_LABEL: Record<TaskStatus, string> = {
  pending: 'Pending',
  ready: 'Ready',
  'in-progress': 'In Progress',
  blocked: 'Blocked',
  completed: 'Completed',
  cancelled: 'Cancelled',
  delayed: 'Delayed',
}

export const TASK_STATUS_TONE: Record<TaskStatus, Tone> = {
  pending: 'neutral',
  ready: 'info',
  'in-progress': 'brand',
  blocked: 'danger',
  completed: 'success',
  cancelled: 'neutral',
  delayed: 'warning',
}

/**
 * The bar colour on the timeline and calendar, keyed by status.
 *
 * Status is what a planner scans for, so it drives the colour; category drives the
 * grouping instead. Both on colour at once would make neither readable.
 */
export const TASK_STATUS_FILL: Record<TaskStatus, string> = {
  pending: 'bg-white/25',
  ready: 'bg-status-info',
  'in-progress': 'bg-brand',
  blocked: 'bg-status-danger',
  completed: 'bg-status-success',
  cancelled: 'bg-white/15',
  delayed: 'bg-status-warning',
}

export const TASK_PRIORITY_LABEL: Record<TaskPriority, string> = {
  critical: 'Critical',
  high: 'High',
  medium: 'Medium',
  low: 'Low',
}

export const TASK_PRIORITY_TONE: Record<TaskPriority, Tone> = {
  critical: 'danger',
  high: 'warning',
  medium: 'info',
  low: 'neutral',
}

export const SCHEDULE_STATE_LABEL: Record<ScheduleState, string> = {
  draft: 'Draft',
  published: 'Published',
  'in-progress': 'In Progress',
  'on-hold': 'On Hold',
  completed: 'Completed',
}

export const SCHEDULE_STATE_TONE: Record<ScheduleState, Tone> = {
  draft: 'neutral',
  published: 'info',
  'in-progress': 'brand',
  'on-hold': 'warning',
  completed: 'success',
}

export const DEPENDENCY_TYPE_LABEL: Record<DependencyType, string> = {
  finish_to_start: 'Finish → Start',
  start_to_start: 'Start → Start',
  finish_to_finish: 'Finish → Finish',
}

/** Weekday toggles for the working-week editor, ISO numbering. */
export const ISO_WEEKDAYS = [
  { label: 'Mon', value: 1 },
  { label: 'Tue', value: 2 },
  { label: 'Wed', value: 3 },
  { label: 'Thu', value: 4 },
  { label: 'Fri', value: 5 },
  { label: 'Sat', value: 6 },
  { label: 'Sun', value: 7 },
] as const

/** Tabs across the schedule screen. */
export const SCHEDULE_TABS = [
  { label: 'Overview', value: 'overview' },
  { label: 'Tasks', value: 'tasks' },
  { label: 'Timeline', value: 'timeline' },
  { label: 'Calendar', value: 'calendar' },
  { label: 'Crew', value: 'crew' },
  { label: 'Dependencies', value: 'dependencies' },
] as const

export type ScheduleTab = (typeof SCHEDULE_TABS)[number]['value']

/** Human labels for the activity trail's machine types. */
export const SCHEDULE_ACTIVITY_LABEL: Record<string, string> = {
  schedule_created: 'Schedule created',
  schedule_updated: 'Schedule changed',
  task_created: 'Task created',
  task_updated: 'Task updated',
  task_assigned: 'Task assigned',
  task_unassigned: 'Resource removed',
  task_completed: 'Task completed',
  task_delayed: 'Task delayed',
  task_deleted: 'Task deleted',
  task_reordered: 'Tasks reordered',
  task_moved: 'Task rescheduled',
  dependency_added: 'Dependency added',
  dependency_removed: 'Dependency removed',
}
