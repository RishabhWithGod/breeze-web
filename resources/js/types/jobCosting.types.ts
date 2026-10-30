/**
 * Job Costing — estimated vs actual across labor, materials, equipment and
 * other cost, plus revenue and the profit they net out to. Mirrors
 * `JobCostSummary::for()` and `JobCostingController`. Nothing here is
 * computed twice: every number is exactly what the backend service returned.
 */

/**
 * One job's full costing row — the dashboard's table rows and rankings, and
 * the detail screen's summary. Every dollar-denominated field is nullable:
 * the backend sends `null` in place of a real figure for a role without
 * `viewJobCosts` — never the real number left for the frontend to hide.
 */
export interface JobCostRow {
  readonly jobId: number
  readonly jobName: string
  readonly jobStatus: string
  readonly client: string | null
  readonly estimateId: number | null
  readonly estimateNumber: string | null

  /** The estimate at the moment the job was raised, the live scope since, and whether the job is completed (scope frozen as final). */
  readonly hasBaseline: boolean
  readonly isFinal: boolean
  readonly baselineTotal: number | null
  readonly currentScopeTotal: number | null
  readonly scopeChange: number | null

  readonly estimatedLaborHours: number
  readonly actualLaborHours: number
  readonly laborHoursVariance: number
  readonly estimatedLaborCost: number | null
  readonly actualLaborCost: number | null
  readonly laborCostVariance: number | null

  readonly estimatedMaterialCost: number | null
  readonly actualMaterialCost: number | null
  readonly materialCostVariance: number | null

  readonly estimatedEquipmentCost: number | null
  readonly actualEquipmentCost: number | null
  readonly equipmentCostVariance: number | null

  readonly estimatedOtherCost: number | null
  readonly actualOtherCost: number | null
  readonly otherCostVariance: number | null

  readonly estimatedTotalCost: number | null
  readonly actualTotalCost: number | null
  readonly totalCostVariance: number | null
  readonly totalCostVariancePct: number | null

  readonly revenue: number | null
  readonly billed: number | null
  readonly paid: number | null
  readonly outstanding: number | null
  readonly unbilled: number | null

  readonly profit: number | null
  readonly marginPct: number | null

  readonly isOverBudget: boolean
  readonly overrunReason: string | null
  readonly overrunAmount: number | null
  readonly overrunPct: number | null
}

export type AnalyticsTab = 'overview' | 'profitability' | 'labor' | 'materials' | 'billing' | 'alerts'

export interface AnalyticsSettings {
  readonly low_margin_pct: number
  readonly hours_warning_pct: number
  readonly stale_timer_hours: number
  readonly overdue_grace_days: number
}

export interface AnalyticsTrendPoint {
  readonly month: string
  readonly label: string
  readonly billed: number
  readonly collected: number
  readonly cost: number
  readonly net: number
}

export interface AnalyticsComparison {
  readonly from: string
  readonly to: string
  readonly laborHours: number
  readonly laborCost: number
  readonly totalCost: number
}

export interface AnalyticsCostCategory {
  readonly key: 'labor' | 'material' | 'equipment' | 'other'
  readonly label: string
  readonly estimated: number
  readonly actual: number
  readonly variance: number
  readonly variancePct: number | null
}

export interface AnalyticsAlert {
  readonly id: string
  readonly type: 'overrun' | 'hours_warning' | 'low_margin' | 'unbilled' | 'overdue_invoice' | 'missing_checkout'
  readonly severity: 'danger' | 'warning'
  readonly jobId: number | null
  readonly jobName: string | null
  readonly title: string
  readonly detail: string
}

export interface AnalyticsLaborPerson {
  readonly name: string
  readonly role: string
  readonly hours: number
  readonly regularHours: number
  readonly overtimeHours: number
  readonly overtimePct: number
  readonly billableHours: number
  readonly billablePct: number
  readonly jobs: number
  readonly cost: number | null
}

export interface AnalyticsLaborRole {
  readonly role: string
  readonly people: number
  readonly hours: number
  readonly overtimeHours: number
  readonly overtimePct: number
  readonly cost: number | null
}

export interface AnalyticsLabor {
  readonly people: readonly AnalyticsLaborPerson[]
  readonly roles: readonly AnalyticsLaborRole[]
  readonly totals: {
    readonly hours: number
    readonly overtimeHours: number
    readonly overtimePct: number
    readonly billableHours: number
    readonly billablePct: number
  }
}

export interface AnalyticsBillingInvoice {
  readonly id: number
  readonly number: string
  readonly client: string | null
  readonly jobId: number | null
  readonly jobName: string | null
  readonly dueDate: string | null
  readonly balance: number
  readonly daysOverdue: number
  readonly isOverdue: boolean
}

export interface AnalyticsBillingReport {
  readonly aging: { readonly current: number; readonly d1_30: number; readonly d31_60: number; readonly d61_plus: number }
  readonly overdueTotal: number
  readonly overdueCount: number
  readonly invoices: readonly AnalyticsBillingInvoice[]
  readonly byClient: readonly { readonly client: string; readonly invoices: number; readonly balance: number }[]
}

export interface JobCostingFilters {
  readonly date_from: string | null
  readonly date_to: string | null
  readonly job: number | null
  readonly client: string
  readonly status: string
  readonly cost_type: string
  readonly team_member: number | null
  readonly tab: AnalyticsTab
}

export interface JobCostingJobOption {
  readonly id: number
  readonly name: string
  readonly client: string | null
}

export interface JobCostingTeamMemberOption {
  readonly id: number
  readonly name: string
}

export interface JobsByStatusRow {
  readonly status: string
  readonly count: number
}

export interface JobCostingDashboardProps {
  filters: JobCostingFilters
  canViewCosts: boolean
  laborTotals: {
    readonly estimatedHours: number
    readonly actualHours: number
    readonly estimatedCost: number | null
    readonly actualCost: number | null
  }
  materialTotals: {
    readonly estimatedCost: number | null
    readonly actualCost: number | null
  }
  profitLoss: {
    readonly revenue: number | null
    readonly totalCost: number | null
    readonly profit: number | null
    readonly marginPct: number | null
  }
  billing: {
    readonly billed: number | null
    readonly paid: number | null
    readonly outstanding: number | null
    readonly unbilled: number | null
  }
  overBudgetCount: number
  scope: {
    readonly baseline: number | null
    readonly current: number | null
    readonly change: number | null
    readonly finalJobs: number
    readonly liveJobs: number
  }
  tab: AnalyticsTab
  settings: AnalyticsSettings
  can: { readonly manage: boolean }
  comparison: AnalyticsComparison | null
  costBreakdown: readonly AnalyticsCostCategory[]
  selectedCost: number | null
  trend: readonly AnalyticsTrendPoint[] | null
  alerts: readonly AnalyticsAlert[]
  labor: AnalyticsLabor | null
  billingReport: AnalyticsBillingReport | null
  jobRows: readonly JobCostRow[] | null
  overrunAlerts: readonly JobCostRow[]
  jobsByStatus: readonly JobsByStatusRow[]
  topProfitable: readonly JobCostRow[]
  leastProfitable: readonly JobCostRow[]
  jobCount: number
  clients: readonly string[]
  jobs: readonly JobCostingJobOption[]
  teamMembers: readonly JobCostingTeamMemberOption[]
}

/** The Job Costing detail screen's labor table — one row per real person. */
export interface JobCostingLaborRow {
  readonly name: string
  readonly role: string | null
  readonly hours: number
  readonly regularHours: number
  readonly overtimeHours: number
  readonly billableHours: number
  readonly laborRate: number | null
  readonly laborCost: number | null
}

/** One logged actual material/equipment/other cost. */
export interface JobCostEntryRow {
  readonly id: number
  readonly category: 'material' | 'equipment' | 'other'
  readonly description: string
  readonly quantity: number | null
  readonly unitCost: number | null
  readonly amount: number | null
  readonly incurredOn: string
  readonly recordedBy: string | null
}

/** One estimate line offered for comparison against actual cost entries. */
export interface JobCostingEstimatedItemRow {
  readonly category: string
  readonly description: string
  readonly quantity: number
  readonly cost: number | null
}

export interface JobCostingScheduleInfo {
  readonly hasSchedule: boolean
  readonly scheduledHours: number | null
  readonly remainingHours: number
}

/** One remembered change to a job's scope — an estimate edit, a change order, a logged cost, an invoice adjustment. */
export interface ScopeChangeLine {
  readonly action: 'added' | 'removed' | 'changed' | 'settings'
  readonly category?: string
  readonly description?: string
  readonly unit?: string
  readonly qty_before?: number | null
  readonly qty_after?: number | null
  /** Absent for a role without cost access. */
  readonly total_before?: number | null
  readonly total_after?: number | null
  readonly settings?: Record<string, { readonly from: unknown; readonly to: unknown }>
}

export interface ScopeChange {
  readonly id: number
  readonly version: number
  readonly kind: 'estimate' | 'change_order' | 'cost_entry' | 'invoice' | 'hours'
  readonly source: 'web' | 'builder' | 'mobile' | 'field' | 'invoice' | 'system'
  readonly by: string | null
  readonly at: string | null
  readonly estimateId: number | null
  readonly summary: string
  readonly lines: readonly ScopeChangeLine[]
  /** Null for a role without cost access. */
  readonly amount: number | null
  readonly totalBefore: number | null
  readonly totalAfter: number | null
}

/** The job's scope version and its newest changes. */
export interface ScopePayload {
  readonly version: number
  readonly history: readonly ScopeChange[]
}
