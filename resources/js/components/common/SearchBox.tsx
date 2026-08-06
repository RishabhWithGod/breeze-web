import { forwardRef, type InputHTMLAttributes } from 'react'
import { Search, X } from 'lucide-react'
import { cn } from '@/utils'

export interface SearchBoxProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value'> {
  value: string
  onValueChange: (value: string) => void
  /** Shows a clear affordance once there is a query. */
  clearable?: boolean
  containerClassName?: string
}

export const SearchBox = forwardRef<HTMLInputElement, SearchBoxProps>(
  function SearchBox(
    {
      value,
      onValueChange,
      clearable = true,
      placeholder = 'Search…',
      className,
      containerClassName,
      ...props
    },
    ref,
  ) {
    return (
      <div className={cn('relative w-full', containerClassName)}>
        <Search
          size={18}
          aria-hidden
          className="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-white/55"
        />
        <input
          ref={ref}
          type="search"
          value={value}
          placeholder={placeholder}
          onChange={(event) => onValueChange(event.target.value)}
          className={cn(
            'w-full rounded-pill border border-transparent bg-surface-input py-2.5 pr-10 pl-11',
            'text-md text-white placeholder:text-white/50',
            'transition-colors duration-200 hover:bg-white/25',
            'focus:border-brand/60 focus:bg-white/25 focus:outline-none',
            '[&::-webkit-search-cancel-button]:hidden',
            className,
          )}
          {...props}
        />
        {clearable && value.length > 0 && (
          <button
            type="button"
            onClick={() => onValueChange('')}
            aria-label="Clear search"
            className="absolute top-1/2 right-3 -translate-y-1/2 rounded-full p-1 text-white/60 transition-colors hover:bg-white/15 hover:text-white"
          >
            <X size={15} aria-hidden />
          </button>
        )}
      </div>
    )
  },
)
