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

export interface JobCostingFilters {
  readonly date_from: string
  readonly date_to: string
  readonly job: number | null
  readonly client: string
  readonly status: string
  readonly cost_type: string
  readonly team_member: number | null
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
