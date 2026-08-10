import { motion } from 'framer-motion'
import { cn } from '@/utils'

export interface FilterTabsProps<T extends string> {
  options: readonly { readonly label: string; readonly value: T }[]
  value: T
  onChange: (value: T) => void
  /** Optional counts keyed by option value. */
  counts?: Partial<Record<T, number>>
  /** Gives inactive pills a visible translucent fill instead of bare text. */
  solid?: boolean
  className?: string
}

/** Pill-style segmented control, mirroring the reference `list-nav`. */
export function FilterTabs<T extends string>({
  options,
  value,
  onChange,
  counts,
  solid = false,
  className,
}: FilterTabsProps<T>) {
  return (
    <div
      role="tablist"
      aria-label="Filter"
      className={cn('flex flex-wrap items-center gap-1.5', className)}
    >
      {options.map((option) => {
        const isActive = option.value === value
        const count = counts?.[option.value]

        return (
          <button
            key={option.value}
            type="button"
            role="tab"
            aria-selected={isActive}
            onClick={() => onChange(option.value)}
            className={cn(
              'relative inline-flex items-center gap-2 rounded-panel px-4 py-2 text-md font-medium transition-colors duration-200',
              isActive
                ? 'text-white'
                : solid
                  ? 'bg-white/25 text-white hover:bg-white/35'
                  : 'text-white/90 hover:bg-white/15 hover:text-white',
            )}
          >
            {isActive && (
              <motion.span
                layoutId="filter-tab-active"
                transition={{ type: 'spring', stiffness: 420, damping: 34 }}
                className="absolute inset-0 rounded-panel bg-brand/45"
                aria-hidden
              />
            )}
            <span className="relative">{option.label}</span>
            {typeof count === 'number' && (
              <span
                className={cn(
                  'relative rounded-full px-2 py-0.5 text-2xs font-semibold',
                  isActive ? 'bg-navy-950/40 text-white' : 'bg-white/10 text-white/90',
                )}
              >
                {count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}
