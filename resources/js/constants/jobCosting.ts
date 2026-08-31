/** Mirrors the `cost_type` values `JobCostingController` accepts. */
export const JOB_COSTING_COST_TYPE_FILTERS = [
  { label: 'All Cost Types', value: 'all' },
  { label: 'Labor', value: 'labor' },
  { label: 'Materials', value: 'material' },
  { label: 'Equipment', value: 'equipment' },
  { label: 'Other', value: 'other' },
  { label: 'Total', value: 'total' },
] as const

export type JobCostingCostTypeFilter = (typeof JOB_COSTING_COST_TYPE_FILTERS)[number]['value']

/** Human labels for a cost entry's category. */
export const JOB_COST_ENTRY_CATEGORY_LABEL: Record<string, string> = {
  material: 'Material',
  equipment: 'Equipment',
  other: 'Other',
}

/** Human labels for an overrun's reason, used by the Cost Overrun Alerts card. */
export const JOB_COSTING_OVERRUN_LABEL: Record<string, string> = {
  labor_hours: 'Labor hours exceeding budget',
  labor_cost: 'Labor costs exceeding budget',
  material_cost: 'Material costs exceeding budget',
  total_cost: 'Total cost exceeding budget',
}
