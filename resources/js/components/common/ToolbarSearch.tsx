import { Search, X } from 'lucide-react'
import { cn } from '@/utils'

export interface ToolbarSearchProps {
  value: string
  onChange: (value: string) => void
  /** Called with the typed text on Enter, and with '' when the clear button is used. */
  onSubmit: (value: string) => void
  /** Whether a search is currently applied — shows the clear button even after the box is emptied. */
  applied?: boolean
  placeholder?: string
  ariaLabel?: string
}

/** The search box in a card's toolbar: 360×36, icon at 11px, Enter to search. */
export function ToolbarSearch({ value, onChange, onSubmit, applied = false, placeholder = 'Search', ariaLabel }: ToolbarSearchProps) {
  return (
    <label className="relative block w-full max-w-[360px] flex-1">
      <Search
        size={15}
        aria-hidden
        className="pointer-events-none absolute top-1/2 left-[11px] -translate-y-1/2 text-[var(--text-3)]"
      />
      <input
        type="search"
        value={value}
        aria-label={ariaLabel ?? placeholder}
        placeholder={placeholder}
        onChange={(event) => onChange(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === 'Enter') onSubmit(value)
        }}
        className={cn(
          'h-9 w-full rounded-panel border border-hairline-strong bg-surface-input pl-[34px] text-base text-white transition-colors placeholder:text-white/45 hover:border-brand/40 focus:border-brand focus:ring-[3px] focus:ring-brand/25 focus:outline-none',
          value !== '' || applied ? 'pr-8' : 'pr-[11px]',
        )}
      />
      {(value !== '' || applied) && (
        <button
          type="button"
          aria-label="Clear search"
          onClick={() => {
            onChange('')
            onSubmit('')
          }}
          className="absolute top-1/2 right-2 grid size-6 -translate-y-1/2 place-items-center rounded-full text-white/55 hover:bg-white/8 hover:text-white"
        >
          <X size={14} aria-hidden />
        </button>
      )}
    </label>
  )
}
