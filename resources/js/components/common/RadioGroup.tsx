import { cn } from '@/utils'

export interface RadioOption {
  readonly label: string
  readonly value: string
}

export interface RadioGroupProps {
  /** Shared input name — required for native radio grouping. */
  name: string
  label?: string
  options: readonly RadioOption[]
  value: string
  onChange: (value: string) => void
  error?: string
  disabled?: boolean
  /** Columns at `sm` and up; stacks on the narrowest screens. */
  columns?: 2 | 3
  className?: string
}

const COLUMNS = {
  2: 'sm:grid-cols-2',
  3: 'sm:grid-cols-3',
} as const

/**
 * Radio set built on visually-hidden native inputs, so arrow-key navigation and
 * screen-reader grouping stay standard.
 */
export function RadioGroup({
  name,
  label,
  options,
  value,
  onChange,
  error,
  disabled = false,
  columns = 3,
  className,
}: RadioGroupProps) {
  return (
    <fieldset className="w-full">
      {label && (
        <legend className="mb-2 text-md font-medium text-white">{label}</legend>
      )}

      <div className={cn('grid gap-3', COLUMNS[columns], className)}>
        {options.map((option) => {
          const id = `${name}-${option.value}`
          const isSelected = option.value === value

          return (
            <label
              key={option.value}
              htmlFor={id}
              className={cn(
                'group inline-flex cursor-pointer items-center gap-2.5 text-md text-white/85 select-none',
                disabled && 'cursor-not-allowed opacity-50',
              )}
            >
              <input
                id={id}
                type="radio"
                name={name}
                value={option.value}
                checked={isSelected}
                disabled={disabled}
                onChange={() => onChange(option.value)}
                className="peer sr-only"
              />
              <span
                aria-hidden
                className={cn(
                  'grid size-5 shrink-0 place-items-center rounded-full border transition-colors',
                  'peer-focus-visible:outline-2 peer-focus-visible:outline-brand peer-focus-visible:outline-offset-2',
                  isSelected
                    ? 'border-brand bg-brand/15'
                    : 'border-hairline-strong bg-white/10 group-hover:border-brand/60',
                )}
              >
                <span
                  className={cn(
                    'size-2.5 rounded-full bg-brand transition-transform',
                    isSelected ? 'scale-100' : 'scale-0',
                  )}
                />
              </span>
              <span className="transition-colors group-hover:text-white">
                {option.label}
              </span>
            </label>
          )
        })}
      </div>

      {error && <p className="mt-2 text-sm text-red-300">{error}</p>}
    </fieldset>
  )
}
