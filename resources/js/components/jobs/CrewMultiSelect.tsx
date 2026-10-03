import { useMemo, useRef, useState } from 'react'
import { Check, ChevronDown, Search, X } from 'lucide-react'
import { FieldShell } from '@/components/common/Field'
import { StatusChip } from '@/components/common'
import type { Tone } from '@/types'
import { cn } from '@/utils'

export interface CrewMultiSelectPerson {
  readonly id: number
  readonly name: string
  /** 'foreman' | 'journeyman' | 'apprentice'. */
  readonly role: string
  /** "Foreman" / "Journeyman" / "Apprentice", as a screen writes it. */
  readonly roleLabel: string
}

const ROLE_TONE: Record<string, Tone> = {
  foreman: 'info',
  journeyman: 'neutral',
  apprentice: 'brand',
}

export interface CrewMultiSelectProps {
  id: string
  label: string
  /** Everyone the task can be given to. */
  people: readonly CrewMultiSelectPerson[]
  /** The picked people's ids, as strings. */
  value: readonly string[]
  onChange: (ids: string[]) => void
  placeholder?: string
  error?: string
  disabled?: boolean
}

/**
 * Pick any number of crew members for a task from one dropdown.
 *
 * Each person is shown with the role they hold, as a chip beside the name in
 * the list and again on the picked chips — journeymen run the work and foremen
 * are over it, and which side someone lands on follows their role, so there is
 * no second field to fill in.
 */
export function CrewMultiSelect({
  id,
  label,
  people,
  value,
  onChange,
  placeholder = 'Select members',
  error,
  disabled = false,
}: CrewMultiSelectProps) {
  const [open, setOpen] = useState(false)
  const [term, setTerm] = useState('')
  const search = useRef<HTMLInputElement>(null)

  const picked = useMemo(
    () => people.filter((person) => value.includes(String(person.id))),
    [people, value],
  )

  const rows = useMemo(() => {
    const needle = term.trim().toLowerCase()

    return needle === ''
      ? people
      : people.filter(
          (person) =>
            person.name.toLowerCase().includes(needle) || person.roleLabel.toLowerCase().includes(needle),
        )
  }, [people, term])

  const toggle = (personId: number) => {
    const key = String(personId)
    onChange(value.includes(key) ? value.filter((existing) => existing !== key) : [...value, key])
  }

  const close = () => {
    setOpen(false)
    setTerm('')
  }

  return (
    <FieldShell label={label} htmlFor={id} {...(error ? { error } : {})}>
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
          {picked.length === 0 ? (
            <span className="truncate text-white/75">{people.length > 0 ? placeholder : 'No one on this crew'}</span>
          ) : (
            <span className="flex flex-wrap items-center gap-1.5">
              {picked.map((person) => (
                <span
                  key={person.id}
                  className="inline-flex items-center gap-1.5 rounded-full border border-hairline bg-white/10 py-0.5 pr-1 pl-2.5 text-sm text-white"
                >
                  {person.name}
                  <StatusChip hideDot tone={ROLE_TONE[person.role] ?? 'neutral'} label={person.roleLabel} />
                  <span
                    role="button"
                    tabIndex={0}
                    aria-label={`Remove ${person.name}`}
                    className="rounded-full p-0.5 text-white/70 hover:bg-white/15 hover:text-white"
                    onClick={(event) => {
                      event.stopPropagation()
                      toggle(person.id)
                    }}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault()
                        event.stopPropagation()
                        toggle(person.id)
                      }
                    }}
                  >
                    <X size={12} aria-hidden />
                  </span>
                </span>
              ))}
            </span>
          )}
          <ChevronDown
            size={17}
            aria-hidden
            className={cn('shrink-0 text-white/80 transition-transform', open && 'rotate-180')}
          />
        </button>

        {open && (
          <>
            {/* Clicking anywhere else closes it — a backdrop rather than a document listener. */}
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
                  value={term}
                  onChange={(event) => setTerm(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Escape') close()
                  }}
                  placeholder="Search members"
                  aria-label="Search members"
                  className="w-full bg-transparent py-2.5 pr-4 pl-10 text-md text-white placeholder:text-white/60 focus:outline-none"
                />
              </div>

              <ul role="listbox" aria-multiselectable className="max-h-60 overflow-y-auto py-1">
                {rows.length === 0 ? (
                  <li className="px-4 py-3 text-sm text-white/70">Nothing matches that.</li>
                ) : (
                  rows.map((person) => {
                    const selected = value.includes(String(person.id))

                    return (
                      <li key={person.id}>
                        <button
                          type="button"
                          role="option"
                          aria-selected={selected}
                          onClick={() => toggle(person.id)}
                          className={cn(
                            'flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-md transition-colors hover:bg-white/10',
                            selected ? 'text-white' : 'text-white/85',
                          )}
                        >
                          <span className="flex min-w-0 items-center gap-2.5">
                            <span
                              aria-hidden
                              className={cn(
                                'grid size-4.5 shrink-0 place-items-center rounded-sm border',
                                selected ? 'border-brand bg-brand text-navy-900' : 'border-hairline-strong',
                              )}
                            >
                              {selected && <Check size={13} />}
                            </span>
                            <span className="truncate">{person.name}</span>
                          </span>
                          <StatusChip hideDot tone={ROLE_TONE[person.role] ?? 'neutral'} label={person.roleLabel} />
                        </button>
                      </li>
                    )
                  })
                )}
              </ul>

              <div className="flex items-center justify-between gap-3 border-t border-hairline px-4 py-2 text-sm text-white/70">
                <span>
                  {picked.length} selected
                </span>
                <button type="button" onClick={close} className="font-medium text-brand hover:underline">
                  Done
                </button>
              </div>
            </div>
          </>
        )}
      </div>
    </FieldShell>
  )
}
