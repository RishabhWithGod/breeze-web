/**
 * Breeze Bucks — a real, ledger-derived balance and reward catalog. Mirrors
 * `BreezeBucksController::index()` field-for-field; nothing here is
 * computed twice on the client.
 */

export type BreezeBucksTransactionType = 'earned' | 'redeemed' | 'adjustment' | 'bonus' | 'reversal'

export interface BreezeBucksHistoryRow {
  readonly id: number
  readonly date: string
  readonly type: BreezeBucksTransactionType
  readonly description: string
  readonly amount: number
  readonly balanceAfter: number
  readonly sourceType: string | null
  readonly status: string
}

export interface BreezeBucksHistoryPage {
  readonly data: readonly BreezeBucksHistoryRow[]
  readonly meta: {
    readonly current_page: number
    readonly last_page: number
    readonly total: number
  }
}

export interface RewardCatalogItemData {
  readonly id: number
  readonly name: string
  readonly description: string | null
  readonly pointsRequired: number
  readonly icon: string | null
  readonly stock: number | null
  readonly isActive: boolean
  readonly isRedeemable: boolean
}

export interface NextRewardInfo {
  readonly name: string
  readonly pointsRequired: number
  readonly remaining: number
  readonly percent: number
}

export interface BreezeBucksTeamMember {
  readonly id: number
  readonly name: string
  readonly role: string
}

export interface BreezeBucksAwardRow {
  readonly id: number
  readonly date: string
  readonly amount: number
  readonly reason: string
  readonly recipient: {
    readonly name: string
    readonly role: string
  }
}
