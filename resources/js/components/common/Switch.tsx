import { forwardRef, type InputHTMLAttributes } from 'react'
import { cn } from '@/utils'

export interface SwitchProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label?: string
}

/**
 * A pill toggle built on a visually-hidden native checkbox input, mirroring
 * `Checkbox`'s group-has-* projection technique so keyboard/screen-reader
 * behaviour stays standard.
 */
export const Switch = forwardRef<HTMLInputElement, SwitchProps>(function Switch(
  { label, id, className, ...props },
  ref,
) {
  return (
    <label
      htmlFor={id}
      className={cn(
        'group inline-flex cursor-pointer items-center gap-2.5 select-none',
        'has-[input:disabled]:cursor-not-allowed has-[input:disabled]:opacity-50',
        className,
      )}
    >
      <input ref={ref} id={id} type="checkbox" className="sr-only" {...props} />
      <span
        aria-hidden
        className={cn(
          'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border border-hairline-strong bg-white/10 transition-colors',
          'group-has-[input:checked]:border-brand group-has-[input:checked]:bg-brand',
          'group-has-[input:focus-visible]:outline-2 group-has-[input:focus-visible]:outline-brand group-has-[input:focus-visible]:outline-offset-2',
        )}
      >
        <span
          className={cn(
            'inline-block size-4 translate-x-1 rounded-full bg-white transition-transform',
            'group-has-[input:checked]:translate-x-6',
          )}
        />
      </span>
      {label ? <span className="text-md text-white transition-colors group-hover:text-white">{label}</span> : null}
    </label>
  )
})
