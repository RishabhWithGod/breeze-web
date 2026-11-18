// Breezy calls the Breeze app's data client (`api.<area>.<method>()`). This app
// has none for it, so every call rejects: Breezy treats that as "no live data"
// and falls back to its built-in answers.
type Deep = { [key: string]: Deep } & ((...args: unknown[]) => Promise<never>)

function deep(): Deep {
  const fn = (() => Promise.reject(new Error('Breezy has no data source in this app'))) as unknown as Deep
  return new Proxy(fn, { get: (_target, prop) => (prop === 'then' ? undefined : deep()) })
}

const api: Deep = deep()
export default api
