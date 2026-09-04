import {
  forwardRef,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react'
import { ChevronDown, type LucideIcon } from 'lucide-react'
import { DateControl } from './DateControl'
import type { SelectOption } from '@/types'
import { cn } from '@/utils'

const CONTROL_BASE =
  'w-full rounded-panel border border-transparent bg-surface-veil/50 px-4 py-2.5 text-md text-white ' +
  'placeholder:text-white/75 transition-colors duration-200 ' +
  'hover:border-hairline-strong focus:border-brand focus:bg-white/25 focus:outline-none ' +
  'disabled:cursor-not-allowed disabled:opacity-50'

const INVALID = 'border-status-danger/70 focus:border-status-danger'

interface FieldShellProps {
  label?: string
  htmlFor?: string
  hint?: ReactNode
  error?: string
  /** Right-aligned helper, e.g. a character counter. */
  addon?: ReactNode
  className?: string
  children: ReactNode
}

/** Shared label / hint / error scaffolding for all form controls. */
export function FieldShell({
  label,
  htmlFor,
  hint,
  error,
  addon,
  className,
  children,
}: FieldShellProps) {
  return (
    <div className={cn('w-full', className)}>
      {(label || addon) && (
        <div className="mb-2 flex items-center justify-between gap-3">
          {label && (
            <label htmlFor={htmlFor} className="text-md font-medium text-white">
              {label}
            </label>
          )}
          {addon && <span className="text-xs text-white/75">{addon}</span>}
        </div>
      )}

      {children}

      {error ? (
        <p className="mt-2 text-sm text-red-300">{error}</p>
      ) : (
        hint && <p className="mt-2 text-sm text-white/75">{hint}</p>
      )}
    </div>
  )
}

export interface TextInputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string
  hint?: ReactNode
  error?: string
  /** Icon rendered inside the control on the left. */
  leftIcon?: LucideIcon
  /** Interactive element pinned inside the control on the right. */
  rightSlot?: ReactNode
}

export const TextInput = forwardRef<HTMLInputElement, TextInputProps>(
  function TextInput(
    { label, hint, error, leftIcon: LeftIcon, rightSlot, id, className, ...props },
    ref,
  ) {
    return (
      /*
       * `className` sizes the whole field, not the `<input>` — the same rule
       * SelectField follows. Every caller passes a width or a grid span, and
       * putting those on the control left the shell full width: a field asked
       * to span five columns of twelve sat in one of them, with a shrunken box
       * inside it.
       */
      <FieldShell
        {...(label ? { label } : {})}
        {...(id ? { htmlFor: id } : {})}
        {...(hint ? { hint } : {})}
        {...(error ? { error } : {})}
        {...(className ? { className } : {})}
      >
        {/*
          A date is written MM/DD/YYYY everywhere in this app, and a native date
          input draws itself in the browser's own region instead. Swapped here
          rather than at each of the thirty-odd callers, so `type="date"` keeps
          meaning what it always meant: an ISO value in, an ISO value out.
        */}
        {props.type === 'date' ? (
          <DateControl
            {...props}
            {...(id ? { id } : {})}
            {...(error ? { invalid: true } : {})}
            controlClassName={cn(CONTROL_BASE, error && INVALID)}
          />
        ) : (
        <div className="relative">
          {LeftIcon && (
            <LeftIcon
              size={17}
              aria-hidden
              className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-white/80"
            />
          )}
          <input
            ref={ref}
            id={id}
            aria-invalid={Boolean(error) || undefined}
            className={cn(
              CONTROL_BASE,
              LeftIcon && 'pl-11',
              rightSlot && 'pr-12',
              error && INVALID,
            )}
            {...props}
          />
          {rightSlot && (
            <div className="absolute top-1/2 right-2 -translate-y-1/2">{rightSlot}</div>
          )}
        </div>
        )}
      </FieldShell>
    )
  },
)

export interface TextAreaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string
  hint?: ReactNode
  error?: string
  addon?: ReactNode
}

export const TextArea = forwardRef<HTMLTextAreaElement, TextAreaProps>(
  function TextArea({ label, hint, error, addon, id, className, ...props }, ref) {
    return (
      <FieldShell
        {...(label ? { label } : {})}
        {...(id ? { htmlFor: id } : {})}
        {...(hint ? { hint } : {})}
        {...(error ? { error } : {})}
        {...(addon ? { addon } : {})}
      >
        <textarea
          ref={ref}
          id={id}
          aria-invalid={Boolean(error) || undefined}
          className={cn(CONTROL_BASE, 'resize-y leading-relaxed', error && INVALID, className)}
          {...props}
        />
      </FieldShell>
    )
  },
)

export interface SelectFieldProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label?: string
  options: readonly SelectOption[]
  hint?: ReactNode
  error?: string
}

export const SelectField = forwardRef<HTMLSelectElement, SelectFieldProps>(
  function SelectField({ label, options, hint, error, id, className, ...props }, ref) {
    return (
      /*
       * `className` sizes the whole field, not the `<select>`. Every caller
       * passes a width or a grid span, and putting those on the control left
       * the shell full-width — with the chevron, positioned against the shell,
       * stranded far to the right of the box it belongs to.
       */
      <FieldShell
        {...(label ? { label } : {})}
        {...(id ? { htmlFor: id } : {})}
        {...(hint ? { hint } : {})}
        {...(error ? { error } : {})}
        {...(className ? { className } : {})}
      >
        <div className="relative">
          <select
            ref={ref}
            id={id}
            className={cn(
              CONTROL_BASE,
              'appearance-none pr-10 [&>option]:bg-navy-900 [&>option]:text-white',
              error && INVALID,
            )}
            {...props}
          >
            {options.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          <ChevronDown
            size={17}
            aria-hidden
            className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-white/85"
          />
        </div>
      </FieldShell>
    )
  },
)
