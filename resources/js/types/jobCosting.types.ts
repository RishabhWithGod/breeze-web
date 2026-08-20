/**
 * Job Costing — estimated vs actual across labor, materials, equipment and
 * other cost, plus revenue and the profit they net out to. Mirrors
 * `JobCostSummary::for()` and `JobCostingController`. Nothing here is
 * computed twice: every number is exactly what the backend service returned.
 */

/** One job's full costing row — the dashboard's table rows and rankings, and the detail screen's summary. */
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
  readonly estimatedLaborCost: number
  readonly actualLaborCost: number
  readonly laborCostVariance: number

  readonly estimatedMaterialCost: number
  readonly actualMaterialCost: number
  readonly materialCostVariance: number

  readonly estimatedEquipmentCost: number
  readonly actualEquipmentCost: number
  readonly equipmentCostVariance: number

  readonly estimatedOtherCost: number
  readonly actualOtherCost: number
  readonly otherCostVariance: number

  readonly estimatedTotalCost: number
  readonly actualTotalCost: number
  readonly totalCostVariance: number
  readonly totalCostVariancePct: number | null

  readonly revenue: number
  readonly billed: number
  readonly paid: number
  readonly outstanding: number
  readonly unbilled: number

  readonly profit: number
  readonly marginPct: number | null

  readonly isOverBudget: boolean
  readonly overrunReason: string | null
  readonly overrunAmount: number
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
    readonly estimatedCost: number
    readonly actualCost: number
  }
  materialTotals: {
    readonly estimatedCost: number
    readonly actualCost: number
  }
  profitLoss: {
    readonly revenue: number
    readonly totalCost: number
    readonly profit: number
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
  readonly laborCost: number
}

/** One logged actual material/equipment/other cost. */
export interface JobCostEntryRow {
  readonly id: number
  readonly category: 'material' | 'equipment' | 'other'
  readonly description: string
  readonly quantity: number | null
  readonly unitCost: number | null
  readonly amount: number
  readonly incurredOn: string
  readonly recordedBy: string | null
}

/** One estimate line offered for comparison against actual cost entries. */
export interface JobCostingEstimatedItemRow {
  readonly category: string
  readonly description: string
  readonly quantity: number
  readonly cost: number
}

export interface JobCostingScheduleInfo {
  readonly hasSchedule: boolean
  readonly scheduledHours: number | null
  readonly remainingHours: number
}
