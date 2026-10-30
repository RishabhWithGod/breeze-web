import { useState } from 'react'
import { Bookmark, X } from 'lucide-react'
import { Button } from '@/components/common'

interface SavedView {
  readonly name: string
  readonly query: string
}

const STORAGE_KEY = 'analytics.savedViews'

function read(): SavedView[] {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    const parsed: unknown = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed.filter((v): v is SavedView => typeof v?.name === 'string' && typeof v?.query === 'string') : []
  } catch {
    return []
  }
}

function write(views: SavedView[]) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(views))
  } catch {
    /* storage unavailable — the views simply last until reload */
  }
}

/**
 * Named filter sets kept in this browser — a per-viewer convenience, not
 * shared state. A view is just the page's query string.
 */
export function SavedViews({ currentQuery, onApply }: { currentQuery: string; onApply: (query: string) => void }) {
  const [views, setViews] = useState<SavedView[]>(read)
  const [naming, setNaming] = useState(false)
  const [name, setName] = useState('')

  const save = () => {
    const trimmed = name.trim()
    if (!trimmed) return
    const next = [...views.filter((v) => v.name !== trimmed), { name: trimmed, query: currentQuery }]
    setViews(next)
    write(next)
    setName('')
    setNaming(false)
  }

  const remove = (target: string) => {
    const next = views.filter((v) => v.name !== target)
    setViews(next)
    write(next)
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {views.map((view) => (
        <span key={view.name} className="inline-flex items-center overflow-hidden rounded-full border border-hairline-strong bg-white/10 text-sm text-white/90">
          <button type="button" onClick={() => onApply(view.query)} className="inline-flex items-center gap-1.5 py-1 pr-2 pl-3 hover:bg-white/10">
            <Bookmark size={13} aria-hidden />
            {view.name}
          </button>
          <button type="button" onClick={() => remove(view.name)} className="px-2 py-1 hover:bg-white/10" aria-label={`Delete saved view ${view.name}`}>
            <X size={13} aria-hidden />
          </button>
        </span>
      ))}
      {naming ? (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            save()
          }}
          className="inline-flex items-center gap-2"
        >
          <input
            autoFocus
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="View name"
            maxLength={40}
            className="rounded-panel border border-hairline-strong bg-white/10 px-3 py-1 text-sm text-white placeholder:text-white/50"
          />
          <Button size="sm" type="submit">Save</Button>
          <Button size="sm" variant="white" type="button" onClick={() => setNaming(false)}>Cancel</Button>
        </form>
      ) : (
        <Button size="sm" variant="secondary" leftIcon={Bookmark} onClick={() => setNaming(true)}>
          Save view
        </Button>
      )}
    </div>
  )
}
