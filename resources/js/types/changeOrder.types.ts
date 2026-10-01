export type ChangeOrderStatus = 'draft' | 'submitted' | 'approved' | 'rejected'
export type ChangeOrderSource = 'field' | 'office'
export type ChangeOrderLineKind = 'material' | 'labor'

export interface ChangeOrderSummary {
  readonly id: number
  readonly label: string
  readonly description: string
  readonly source: ChangeOrderSource
  readonly status: ChangeOrderStatus
  readonly laborHours: number
  readonly materialCost: number
  readonly amount: number
}
