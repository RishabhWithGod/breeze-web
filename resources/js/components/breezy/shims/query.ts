// Breezy asks react-query for app data this app does not serve to it yet.
// An always-empty, never-failing result is exactly what it sees while loading.
const noop = (): Promise<void> => Promise.resolve()

export function useQuery(_options?: unknown): { data: undefined; isLoading: boolean; isFetching: boolean; error: null; refetch: () => Promise<void> } {
  return { data: undefined, isLoading: false, isFetching: false, error: null, refetch: noop }
}

export function useQueryClient(): Record<string, (...args: unknown[]) => unknown> {
  return new Proxy({}, { get: () => noop }) as Record<string, (...args: unknown[]) => unknown>
}
