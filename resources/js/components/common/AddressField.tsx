import { useEffect, useRef, useState } from 'react'
import { usePage } from '@inertiajs/react'
import { CircleCheck, Loader2, MapPin } from 'lucide-react'
import { TextInput } from './Field'
import { useClickOutside, useDebouncedValue } from '@/hooks'
import { ROUTES } from '@/constants'
import type { PlaceSelection, PlaceSuggestion, SharedPageProps } from '@/types'
import { cn } from '@/utils'

export interface AddressFieldProps {
  id: string
  label?: string
  placeholder?: string
  value: string
  /** Null whenever the address has no place behind it. */
  latitude: number | null
  longitude: number | null
  /**
   * Called with the address and everything known about it. Coordinates and
   * `placeId` are null for anything typed by hand — they are only ever set by
   * choosing a suggestion, so a stored point always belongs to the address
   * stored beside it.
   */
  onChange: (place: PlaceSelection) => void
  error?: string
  hint?: string
  disabled?: boolean
  className?: string
}

/** A new search. Google bills a session — every keystroke plus its one
    details call — as one, so this is minted per search and thrown away on
    selection. */
const newSession = () =>
  typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `s-${Date.now()}-${Math.random().toString(36).slice(2)}`

/**
 * A site address, with the place behind it.
 *
 * The one address input in the product. Typing asks Google Places for names,
 * through our own endpoint so the API key never reaches the browser. Choosing
 * one asks for that place's details — once, on selection, never per keystroke,
 * which is what `place_id` is for.
 *
 * Typing after a selection clears the point and the id, because the old place
 * no longer describes what is in the box.
 *
 * The lookup is an assist, never a gate: with no key configured, the network
 * down, billing off, or an address Google has never heard of, this stays an
 * ordinary text input and the address is saved without a point.
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

  const [suggestions, setSuggestions] = useState<readonly PlaceSuggestion[]>([])
  const [isOpen, setIsOpen] = useState(false)
  const [isSearching, setIsSearching] = useState(false)
  const [isResolving, setIsResolving] = useState(false)
  /** Which row the keyboard is on. -1 while the mouse has the floor. */
  const [active, setActive] = useState(-1)
  /** Set when a search came back with nothing, so the field can say so. */
  const [noMatches, setNoMatches] = useState(false)
  /** Set when a chosen place could not be resolved — the address still saves. */
  const [failed, setFailed] = useState(false)

  /*
   * Only a person typing should trigger a lookup. A value arriving from
   * anywhere else — Create Job copying the client's address the moment a
   * client is picked, or a suggestion just accepted — is already the address
   * that was meant, so searching for it would cost a call and pop a dropdown
   * over a field nobody is looking at.
   */
  const typing = useRef(false)
  const session = useRef(newSession())

  const debounced = useDebouncedValue(value, 350)
  const containerRef = useClickOutside<HTMLDivElement>(() => setIsOpen(false), isOpen)

  useEffect(() => {
    const term = debounced.trim()

    // Three characters: below that a search matches half the country and bills
    // for the privilege.
    if (!addressLookupEnabled || !typing.current || term.length < 3) {
      return undefined
    }

    // Abort in flight: keystrokes outrun the network, and a slow earlier
    // response must never overwrite the answer to what is in the box now.
    const controller = new AbortController()
    setIsSearching(true)

    const query = new URLSearchParams({ q: term, session: session.current })

    fetch(`${ROUTES.addressLookup}?${query.toString()}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then((response) => (response.ok ? response.json() : { suggestions: [] }))
      .then((body: { suggestions?: PlaceSuggestion[] }) => {
        const matches = body.suggestions ?? []
        setSuggestions(matches)
        setIsOpen(matches.length > 0)
        setActive(-1)
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
    setFailed(false)
    // Whatever place was on record described the old address, not this one.
    onChange({ address: next, latitude: null, longitude: null, placeId: null })
  }

  /**
   * The one place details call. The suggestion's own text goes in immediately
   * so the field never looks empty while the point is fetched; the formatted
   * address replaces it when it lands.
   */
  const pick = (suggestion: PlaceSuggestion) => {
    typing.current = false
    setSuggestions([])
    setIsOpen(false)
    setActive(-1)
    setFailed(false)
    setIsResolving(true)

    onChange({
      address: suggestion.label,
      latitude: null,
      longitude: null,
      placeId: suggestion.placeId,
    })

    const query = new URLSearchParams({
      place_id: suggestion.placeId,
      session: session.current,
    })

    fetch(`${ROUTES.addressPlace}?${query.toString()}`, {
      headers: { Accept: 'application/json' },
    })
      .then((response) => (response.ok ? response.json() : { place: null }))
      .then((body: { place?: PlaceSelection | null }) => {
        if (body.place) {
          onChange({
            address: body.place.address,
            latitude: body.place.latitude,
            longitude: body.place.longitude,
            placeId: body.place.placeId,
          })
        } else {
          // Resolvable text, unresolvable place. Keep what was chosen and say
          // so — an address with no point is still a saveable address.
          setFailed(true)
          onChange({
            address: suggestion.label,
            latitude: null,
            longitude: null,
            placeId: null,
          })
        }
      })
      .catch(() => setFailed(true))
      .finally(() => {
        setIsResolving(false)
        // The session ended with this selection; the next search starts a new
        // one, which is what makes Google bill it as one.
        session.current = newSession()
      })
  }

  const onKeyDown = (event: React.KeyboardEvent) => {
    if (event.key === 'Escape') return setIsOpen(false)
    if (!isOpen || suggestions.length === 0) return

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActive((current) => (current + 1) % suggestions.length)
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActive((current) => (current <= 0 ? suggestions.length - 1 : current - 1))
    } else if (event.key === 'Enter' && active >= 0) {
      event.preventDefault()
      const chosen = suggestions[active]
      if (chosen) pick(chosen)
    }
  }

  const located = latitude !== null && longitude !== null

  /*
   * The field never leaves someone typing into silence. It says whether the
   * lookup is switched off at all, whether it is working, whether it found the
   * address, and whether it found nothing — an address with no match is still
   * perfectly saveable, it just has no point behind it.
   */
  const status = (): string | undefined => {
    if (!addressLookupEnabled) {
      return 'Address lookup is off — type the address; no coordinates will be recorded.'
    }
    if (isResolving) return 'Confirming the location…'
    if (located) return 'Location selected'
    if (isSearching) return 'Looking up the address…'
    if (failed) {
      return 'That address could not be confirmed — it is still saved, just without coordinates.'
    }
    if (noMatches && value.trim().length >= 3) {
      return 'No match found — the address is still saved, just without coordinates.'
    }
    if (value.trim().length > 0 && value.trim().length < 3) {
      return 'Keep typing to search for the address.'
    }

    return undefined
  }

  const busy = isSearching || isResolving

  return (
    <div ref={containerRef} className={cn('relative', className)}>
      <TextInput
        id={id}
        autoComplete="off"
        role="combobox"
        aria-expanded={isOpen}
        aria-controls={`${id}-suggestions`}
        aria-activedescendant={active >= 0 ? `${id}-suggestion-${active}` : undefined}
        {...(label ? { label } : {})}
        {...(placeholder ? { placeholder } : {})}
        value={value}
        disabled={disabled}
        onChange={(event) => type(event.target.value)}
        onFocus={() => setIsOpen(suggestions.length > 0)}
        onKeyDown={onKeyDown}
        rightSlot={
          busy ? (
            <Loader2 size={16} aria-hidden className="animate-spin text-white/70" />
          ) : located ? (
            <CircleCheck size={16} aria-hidden className="text-status-success" />
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
          {suggestions.map((suggestion, index) => (
            <li key={suggestion.placeId}>
              <button
                type="button"
                id={`${id}-suggestion-${index}`}
                role="option"
                aria-selected={index === active}
                onMouseEnter={() => setActive(index)}
                onClick={() => pick(suggestion)}
                className={cn(
                  'flex w-full items-start gap-2.5 px-3 py-2 text-left text-sm transition-colors focus:outline-none',
                  index === active ? 'bg-white/10 text-white' : 'text-white',
                )}
              >
                <MapPin size={15} aria-hidden className="mt-0.5 shrink-0 text-brand" />
                <span className="min-w-0">
                  <span className="block truncate">{suggestion.primary}</span>
                  {suggestion.secondary && (
                    <span className="block truncate text-white/60">
                      {suggestion.secondary}
                    </span>
                  )}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
