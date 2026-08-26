import { forwardRef, type InputHTMLAttributes } from 'react'
import { Search, X } from 'lucide-react'
import { cn } from '@/utils'
import { FieldShell } from './Field'

export interface SearchBoxProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value'> {
  value: string
  onValueChange: (value: string) => void
  /**
   * Runs the search itself — pressing Enter or clicking the search button
   * calls this with the current text. Typing alone only updates what's
   * displayed via `onValueChange`; it never searches on its own, so a fast
   * typist never fires a request per keystroke.
   */
  onSearch?: (value: string) => void
  /** Shows a clear affordance once there is a query. Clearing searches immediately, with an empty term. */
  clearable?: boolean
  containerClassName?: string
  /** Renders a label above the field, matching TextInput/SelectField — lets a SearchBox sit level with them in a filter grid. */
  label?: string
  id?: string
}

export const SearchBox = forwardRef<HTMLInputElement, SearchBoxProps>(
  function SearchBox(
    {
      value,
      onValueChange,
      onSearch,
      clearable = true,
      placeholder = 'Search…',
      className,
      containerClassName,
      label,
      id,
      ...props
    },
    ref,
  ) {
    const field = (
      <div className="relative w-full">
        <Search
          size={18}
          aria-hidden
          className="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-white/80"
        />
        <input
          ref={ref}
          id={id}
          type="search"
          value={value}
          placeholder={placeholder}
          onChange={(event) => onValueChange(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') onSearch?.(value)
          }}
          className={cn(
            'w-full rounded-pill border border-transparent bg-surface-input py-2.5 pr-19 pl-11',
            'text-md text-white placeholder:text-white/75',
            'transition-colors duration-200 hover:bg-white/25',
            'focus:border-brand/60 focus:bg-white/25 focus:outline-none',
            '[&::-webkit-search-cancel-button]:hidden',
            className,
          )}
          {...props}
        />

        <div className="absolute top-1/2 right-2 flex -translate-y-1/2 items-center gap-1">
          {clearable && value.length > 0 && (
            <button
              type="button"
              onClick={() => {
                onValueChange('')
                onSearch?.('')
              }}
              aria-label="Clear search"
              className="rounded-full p-1.5 text-white/85 transition-colors hover:bg-white/15 hover:text-white"
            >
              <X size={15} aria-hidden />
            </button>
          )}
          <button
            type="button"
            onClick={() => onSearch?.(value)}
            aria-label="Search"
            className="rounded-full bg-brand p-1.5 text-brand-ink transition-colors hover:bg-brand-soft"
          >
            <Search size={15} aria-hidden />
          </button>
        </div>
      </div>
    )

    if (!label) return <div className={cn('w-full', containerClassName)}>{field}</div>

    return (
      <FieldShell label={label} htmlFor={id} className={containerClassName}>
        {field}
      </FieldShell>
    )
  },
)
