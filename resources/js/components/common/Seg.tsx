import { cn } from '@/utils'

export interface SegOption<T extends string> {
  readonly key: T
  readonly label: string
}

export interface SegProps<T extends string> {
  options: readonly SegOption<T>[]
  value: T
  onChange: (key: T) => void
  ariaLabel?: string
  className?: string
}

/** The Breeze app's segmented switch: a soft track with the current choice lifted onto a white chip. */
export function Seg<T extends string>({ options, value, onChange, ariaLabel, className }: SegProps<T>) {
  return (
    <div
      role="group"
      aria-label={ariaLabel}
      className={cn('inline-flex gap-0.5 rounded-[11px] bg-[var(--surface-3)] p-1', className)}
    >
      {options.map((option) => {
        const on = option.key === value

        return (
          <button
            key={option.key}
            type="button"
            aria-pressed={on}
            onClick={() => onChange(option.key)}
            className={cn(
              'rounded-lg px-3 py-[5px] text-[13px] font-medium transition-colors',
              on ? 'bg-surface-glass text-white shadow-[var(--shadow-sm)]' : 'text-[var(--text-2)] hover:text-white',
            )}
          >
            {option.label}
          </button>
        )
      })}
    </div>
  )
}
