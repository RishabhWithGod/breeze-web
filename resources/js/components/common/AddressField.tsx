import { useEffect, useRef, useState } from 'react'
import { usePage } from '@inertiajs/react'
import { Loader2, MapPin } from 'lucide-react'
import { TextInput } from './Field'
import { useClickOutside, useDebouncedValue } from '@/hooks'
import { ROUTES } from '@/constants'
import type { AddressSuggestion, SharedPageProps } from '@/types'
import { cn } from '@/utils'

export interface AddressFieldProps {
  id: string
  label?: string
  placeholder?: string
  value: string
  /** Null whenever the address has no point behind it. */
  latitude: number | null
  longitude: number | null
  /**
   * Called with the address and its point. `latitude`/`longitude` are null for
   * anything typed by hand — they are only ever set by picking a suggestion,
   * so a stored point always belongs to the address stored beside it.
   */
  onChange: (address: string, latitude: number | null, longitude: number | null) => void
  error?: string
  hint?: string
  disabled?: boolean
  className?: string
}

/**
 * A site address, with the coordinates behind it.
 *
 * Typing searches a geocoder (through our own endpoint, so the token stays
 * server-side) and offers what it matched. Picking one records the point;
 * typing afterwards clears it, because the old point no longer describes what
 * is in the box.
 *
 * The lookup is an assist, never a gate: with the geocoder unconfigured, the
 * network down, or an address it has never heard of, this stays an ordinary
 * text input and the address is saved without a point.
 */
export function AddressField({
  id,
  label,
  placeholder,
  value,
  latitude,
  longitude,
  onChange,
  error,
  hint,
  disabled = false,
  className,
}: AddressFieldProps) {
  const { addressLookupEnabled } = usePage<SharedPageProps>().props

  const [suggestions, setSuggestions] = useState<readonly AddressSuggestion[]>([])
  const [isOpen, setIsOpen] = useState(false)
  const [isSearching, setIsSearching] = useState(false)
  /** Set when a search came back with nothing, so the field can say so. */
  const [noMatches, setNoMatches] = useState(false)

  /*
   * Only a person typing should trigger a lookup. A value arriving from
   * anywhere else — Create Job copying the client's address the moment a
   * client is picked, or a suggestion just accepted — is already the address
   * that was meant, so searching for it would cost a call and pop a dropdown
   * over a field nobody is looking at.
   */
  const typing = useRef(false)

  const debounced = useDebouncedValue(value, 300)
  const containerRef = useClickOutside<HTMLDivElement>(() => setIsOpen(false), isOpen)

  useEffect(() => {
    const term = debounced.trim()

    if (!addressLookupEnabled || !typing.current || term.length < 3) {
      return undefined
    }

    // Abort in flight: keystrokes outrun the network, and a slow earlier
    // response must never overwrite the answer to what is in the box now.
    const controller = new AbortController()
    setIsSearching(true)

    fetch(`${ROUTES.addressLookup}?q=${encodeURIComponent(term)}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then((response) => (response.ok ? response.json() : { suggestions: [] }))
      .then((body: { suggestions?: AddressSuggestion[] }) => {
        const matches = body.suggestions ?? []
        setSuggestions(matches)
        setIsOpen(matches.length > 0)
        setNoMatches(matches.length === 0)
        setIsSearching(false)
      })
      .catch(() => {
        // Includes the abort above, whose replacement is already in flight —
        // so leave the spinner to that one rather than flickering it off.
        if (controller.signal.aborted) return
        setSuggestions([])
        setNoMatches(true)
        setIsSearching(false)
      })

    return () => controller.abort()
  }, [debounced, addressLookupEnabled])

  const type = (next: string) => {
    typing.current = true
    setNoMatches(false)
    // Whatever point was on record described the old address, not this one.
    onChange(next, null, null)
  }

  const pick = (suggestion: AddressSuggestion) => {
    typing.current = false
    setSuggestions([])
    setIsOpen(false)
    onChange(suggestion.label, suggestion.latitude, suggestion.longitude)
  }

  const located = latitude !== null && longitude !== null

  /*
   * The field never leaves someone typing into silence. It says whether the
   * lookup is switched off at all, whether it found the address, and whether
   * it found nothing — an address with no match is still perfectly saveable,
   * it just has no point behind it.
   */
  const status = (): string | undefined => {
    if (!addressLookupEnabled) {
      return 'Address lookup is off — type the address; no coordinates will be recorded.'
    }
    if (located) return `Located at ${latitude.toFixed(5)}, ${longitude.toFixed(5)}`
    if (isSearching) return 'Looking up the address…'
    if (noMatches && value.trim().length >= 3) {
      return 'No match found — the address is still saved, just without coordinates.'
    }
    if (value.trim().length > 0 && value.trim().length < 3) {
      return 'Keep typing to search for the address.'
    }

    return undefined
  }

  return (
    <div ref={containerRef} className={cn('relative', className)}>
      <TextInput
        id={id}
        autoComplete="off"
        role="combobox"
        aria-expanded={isOpen}
        aria-controls={`${id}-suggestions`}
        {...(label ? { label } : {})}
        {...(placeholder ? { placeholder } : {})}
        value={value}
        disabled={disabled}
        onChange={(event) => type(event.target.value)}
        onFocus={() => setIsOpen(suggestions.length > 0)}
        onKeyDown={(event) => {
          if (event.key === 'Escape') setIsOpen(false)
        }}
        rightSlot={
          isSearching ? (
            <Loader2 size={16} aria-hidden className="animate-spin text-white/70" />
          ) : located ? (
            <MapPin size={16} aria-hidden className="text-brand" />
          ) : undefined
        }
        {...(error ? { error } : {})}
        {...(() => {
          const message = hint ?? status()
          return message ? { hint: message } : {}
        })()}
      />

      {isOpen && suggestions.length > 0 && (
        <ul
          id={`${id}-suggestions`}
          role="listbox"
          className="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-panel border border-hairline bg-navy-900 py-1 shadow-panel"
        >
          {suggestions.map((suggestion) => (
            <li key={`${suggestion.latitude},${suggestion.longitude},${suggestion.label}`}>
              <button
                type="button"
                role="option"
                aria-selected={false}
                onClick={() => pick(suggestion)}
                className="flex w-full items-start gap-2.5 px-3 py-2 text-left text-sm text-white transition-colors hover:bg-white/10 focus:bg-white/10 focus:outline-none"
              >
                <MapPin size={15} aria-hidden className="mt-0.5 shrink-0 text-brand" />
                <span className="min-w-0">{suggestion.label}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
