import { useMemo, useRef, useState } from 'react'
import { Check, ChevronDown, Search, X } from 'lucide-react'
import { FieldShell } from './Field'
import type { SelectOption } from '@/types'
import { cn } from '@/utils'

export interface SearchSelectProps {
  id: string
  label?: string
  options: readonly SelectOption[]
  value: string
  onChange: (value: string) => void
  /** What the closed control says when nothing is picked. */
  placeholder?: string
  /** The first row, which clears the choice. Omit to make one required. */
  emptyLabel?: string
  hint?: string
  error?: string
  disabled?: boolean
  className?: string
}

/**
 * A select you can type into.
 *
 * A plain `<select>` is fine for five options and useless for fifty: the list a
 * crew register grows into is one you find by typing a name, not by scrolling.
 * The search narrows what is offered and nothing else — every option is still
 * one of the ones given, so this can never post a value the server did not
 * offer.
 */
export function SearchSelect({
  id,
  label,
  options,
  value,
  onChange,
  placeholder = 'Select…',
  emptyLabel,
  hint,
  error,
  disabled = false,
  className,
}: SearchSelectProps) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const search = useRef<HTMLInputElement>(null)

  const rows = useMemo(() => {
    const term = query.trim().toLowerCase()
    const matched =
      term === ''
        ? options
        : options.filter((option) => option.label.toLowerCase().includes(term))

    // The clearing row is not something you search for, so it is added after
    // the filter rather than being matched against it.
    return emptyLabel === undefined || term !== ''
      ? matched
      : [{ label: emptyLabel, value: '' }, ...matched]
  }, [options, query, emptyLabel])

  const picked = options.find((option) => option.value === value)

  const close = () => {
    setOpen(false)
    setQuery('')
    setActive(0)
  }

  const choose = (next: string) => {
    onChange(next)
    close()
  }

  const keys = (event: React.KeyboardEvent) => {
    if (event.key === 'Escape') return close()

    if (event.key === 'ArrowDown') {
      event.preventDefault()

      return setActive((current) => Math.min(current + 1, rows.length - 1))
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()

      return setActive((current) => Math.max(current - 1, 0))
    }

    if (event.key === 'Enter') {
      event.preventDefault()
      const row = rows[active]
      if (row) choose(row.value)
    }
  }

  return (
    <FieldShell
      {...(label ? { label } : {})}
      htmlFor={id}
      {...(hint ? { hint } : {})}
      {...(error ? { error } : {})}
      {...(className ? { className } : {})}
    >
      <div className="relative">
        <button
          id={id}
          type="button"
          disabled={disabled}
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-invalid={Boolean(error) || undefined}
          onClick={() => {
            if (disabled) return
            setOpen((was) => !was)
            // Focused on the next frame: the input does not exist until the
            // panel is rendered.
            window.requestAnimationFrame(() => search.current?.focus())
          }}
          className={cn(
            'flex w-full items-center justify-between gap-2 rounded-panel border border-transparent',
            'bg-surface-veil/50 px-4 py-2.5 text-left text-md transition-colors duration-200',
            'hover:border-hairline-strong focus:border-brand focus:bg-white/25 focus:outline-none',
            'disabled:cursor-not-allowed disabled:opacity-50',
            error && 'border-status-danger/70',
          )}
        >
          <span className={cn('truncate', picked ? 'text-white' : 'text-white/75')}>
            {picked?.label ?? placeholder}
          </span>
          <ChevronDown
            size={17}
            aria-hidden
            className={cn('shrink-0 text-white/80 transition-transform', open && 'rotate-180')}
          />
        </button>

        {open && (
          <>
            {/*
              Clicking anywhere else closes it. A backdrop rather than a
              document listener: it cannot leak past unmount, and it stops the
              click reaching whatever was behind the panel.
            */}
            <button
              type="button"
              aria-hidden
              tabIndex={-1}
              className="fixed inset-0 z-20 cursor-default"
              onClick={close}
            />

            <div className="absolute z-30 mt-2 w-full overflow-hidden rounded-panel border border-hairline bg-navy-900 shadow-xl">
              <div className="relative border-b border-hairline">
                <Search
                  size={15}
                  aria-hidden
                  className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-white/70"
                />
                <input
                  ref={search}
                  type="text"
                  value={query}
                  placeholder="Type to filter…"
                  aria-label="Filter options"
                  onChange={(event) => {
                    setQuery(event.target.value)
                    setActive(0)
                  }}
                  onKeyDown={keys}
                  className="w-full bg-transparent py-2.5 pr-9 pl-10 text-md text-white placeholder:text-white/60 focus:outline-none"
                />
                {query !== '' && (
                  <button
                    type="button"
                    aria-label="Clear filter"
                    onClick={() => {
                      setQuery('')
                      search.current?.focus()
                    }}
                    className="absolute top-1/2 right-3 -translate-y-1/2 text-white/70 hover:text-white"
                  >
                    <X size={15} aria-hidden />
                  </button>
                )}
              </div>

              <ul role="listbox" className="max-h-60 overflow-y-auto py-1">
                {rows.length === 0 ? (
                  <li className="px-4 py-3 text-sm text-white/70">Nothing matches that.</li>
                ) : (
                  rows.map((row, index) => (
                    <li key={row.value || 'none'}>
                      <button
                        type="button"
                        role="option"
                        aria-selected={row.value === value}
                        onMouseEnter={() => setActive(index)}
                        onClick={() => choose(row.value)}
                        className={cn(
                          'flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left text-md transition-colors',
                          index === active ? 'bg-white/10 text-white' : 'text-white/85',
                          row.value === '' && 'text-white/70 italic',
                        )}
                      >
                        <span className="truncate">{row.label}</span>
                        {row.value === value && (
                          <Check size={15} aria-hidden className="shrink-0 text-brand" />
                        )}
                      </button>
                    </li>
                  ))
                )}
              </ul>
            </div>
          </>
        )}
      </div>
    </FieldShell>
  )
}
