// Stand-in for the Breeze app's list hook. Breezy expects a list back, so it gets
// an empty one: no jobs, invoices or time entries are fed to it yet.
const EMPTY: readonly never[] = []

export function useList(_key: string, _opts?: unknown): { data: readonly never[]; isLoading: boolean } {
  return { data: EMPTY, isLoading: false }
}
