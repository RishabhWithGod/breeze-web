import { useRef, useState, type InputHTMLAttributes } from 'react'
import { CalendarDays } from 'lucide-react'
import { cn, isoToUsDate, maskUsDate, usDateToIso } from '@/utils'

/**
 * A date written the American way, wherever the browser is.
 *
 * A native `<input type="date">` draws itself in the browser's own region
 * setting — dd/mm/yyyy in London, yyyy-mm-dd in Tokyo — and the page cannot
 * change that. So the box people read and type into is a text field we control,
 * and the native input lives behind the calendar button purely to open the
 * picker.
 *
 * The value crossing this component's edge is always `yyyy-MM-dd`, in and out.
 * That is what the server takes and what every form here already holds, so this
 * is a change of appearance and nothing else.
 */

export interface DateControlProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  invalid?: boolean
  /** The classes the surrounding field gives every control. */
  controlClassName: string
}

export function DateControl({
  value,
  onChange,
  invalid,
  controlClassName,
  className,
  id,
  min,
  max,
  disabled,
  required,
  ...props
}: DateControlProps) {
  const iso = typeof value === 'string' ? value : ''
  const native = useRef<HTMLInputElement>(null)

  /*
   * What is in the box. Held separately from the ISO value because a half-typed
   * date — "09/0" — is not one, and clearing the form's value while somebody is
   * still typing would fight them for the field.
   */
  const [text, setText] = useState(() => isoToUsDate(iso))

  /*
   * Follow the value when it changes from outside: a picked date, a reset form,
   * a field filled in from an estimate. Done while rendering rather than in an
   * effect — React re-runs this pass before anything is painted, so the box
   * never shows the old day for a frame.
   *
   * Skipped while the text already means the same day, or typing would be
   * overwritten by its own result.
   */
  const [lastIso, setLastIso] = useState(iso)

  if (iso !== lastIso) {
    setLastIso(iso)
    if (usDateToIso(text) !== iso) setText(isoToUsDate(iso))
  }

  /**
   * Hands the form a real change event from a real input.
   *
   * React reads a controlled input's value off the DOM node, so writing through
   * the prototype's own setter and dispatching `input` produces exactly the
   * event a click on the picker would — rather than a hand-made object shaped
   * to look like one.
   */
  const emit = (next: string) => {
    const element = native.current
    if (!element || element.value === next) return

    const setter = Object.getOwnPropertyDescriptor(
      window.HTMLInputElement.prototype,
      'value',
    )?.set

    setter?.call(element, next)
    element.dispatchEvent(new Event('input', { bubbles: true }))
  }

  const typed = (entry: string) => {
    const masked = maskUsDate(entry)
    setText(masked)

    // Nothing is reported until the day is whole and real, so a form is never
    // handed "2026-09-" on the way to "2026-09-07".
    const parsed = usDateToIso(masked)
    if (parsed !== '' || masked === '') emit(parsed)
  }

  return (
    <div className="relative">
      <input
        {...props}
        id={id}
        type="text"
        inputMode="numeric"
        autoComplete="off"
        placeholder="MM/DD/YYYY"
        maxLength={10}
        disabled={disabled}
        required={required}
        aria-invalid={invalid || undefined}
        value={text}
        onChange={(event) => typed(event.target.value)}
        /* A half-finished date is not a date: leaving the field abandons it
           rather than leaving a number the form does not have. */
        onBlur={() => setText(isoToUsDate(iso))}
        className={cn(controlClassName, 'pr-11', className)}
      />

      <CalendarDays
        size={17}
        aria-hidden
        className="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-white/80"
      />

      {/*
        The picker itself, transparent over the calendar icon. Clicking there
        opens the browser's own date picker — `showPicker()` would be neater but
        is not everywhere yet, and a real input always works.
      */}
      <input
        ref={native}
        type="date"
        tabIndex={-1}
        aria-hidden
        value={iso}
        onChange={onChange}
        {...(min !== undefined ? { min } : {})}
        {...(max !== undefined ? { max } : {})}
        disabled={disabled}
        className="absolute inset-y-0 right-0 w-11 cursor-pointer opacity-0"
      />
    </div>
  )
}
