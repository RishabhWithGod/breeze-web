import { forwardRef, type InputHTMLAttributes } from 'react'
import { Check } from 'lucide-react'
import { cn } from '@/utils'

export interface CheckboxProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label: string
}

/**
 * Styled checkbox built on a visually-hidden native input, so keyboard and
 * screen-reader behaviour stay standard. State is projected onto the custom
 * box with `group-has-*` variants.
 */
export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, id, className, ...props },
  ref,
) {
  return (
    <label
      htmlFor={id}
      className={cn(
        'group inline-flex cursor-pointer items-center gap-2.5 text-md text-white/75 select-none',
        'has-[input:disabled]:cursor-not-allowed has-[input:disabled]:opacity-50',
        className,
      )}
    >
      <input ref={ref} id={id} type="checkbox" className="sr-only" {...props} />
      <span
        aria-hidden
        className={cn(
          'grid size-5 shrink-0 place-items-center rounded-md border border-hairline-strong bg-white/10 transition-colors',
          'group-hover:border-brand/60',
          'group-has-[input:checked]:border-brand group-has-[input:checked]:bg-brand group-has-[input:checked]:text-brand-ink',
          'group-has-[input:focus-visible]:outline-2 group-has-[input:focus-visible]:outline-brand group-has-[input:focus-visible]:outline-offset-2',
        )}
      >
        <Check
          size={13}
          strokeWidth={3}
          className="scale-0 transition-transform duration-150 group-has-[input:checked]:scale-100"
        />
      </span>
      <span className="transition-colors group-hover:text-white">{label}</span>
    </label>
  )
})
