// Calculation helpers Breezy uses on the Breeze app's data model. With no data
// fed to it they are never reached; they return neutral values if they are.
export const timeSplit = (_entry?: unknown): { total: number; regular: number; overtime: number } => ({ total: 0, regular: 0, overtime: 0 })
export const invoiceTotals = (_invoice?: unknown): { total: number; paid: number; balance: number } => ({ total: 0, paid: 0, balance: 0 })
export const jobFinancials = (_db?: unknown, _job?: unknown): { value: number; progress: number; marginPct: number } => ({ value: 0, progress: 0, marginPct: 0 })
