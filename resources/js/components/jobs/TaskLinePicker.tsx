import { useState } from 'react'
import { ChevronDown, Lock, X } from 'lucide-react'
import { Badge, IconButton } from '@/components/common'
import { useClickOutside } from '@/hooks'
import { cn, formatCurrency } from '@/utils'

export interface EstimateLine {
  readonly id: number
  readonly description: string
  readonly category: string | null
  readonly unit: string | null
  readonly quantity: number
  readonly total: number
  /** Set when a task already on the schedule has claimed it. */
  readonly taskId: number | null
  readonly taskTitle: string | null
}

export interface TaskLinePickerProps {
  lines: readonly EstimateLine[]
  /** Line ids on this task. */
  value: readonly number[]
  /** Line ids taken by the *other* rows being typed on this screen. */
  claimedElsewhere: ReadonlySet<number>
  onChange: (ids: number[]) => void
  error?: string
  disabled?: boolean
}

/**
 * The estimate lines a task covers.
 *
 * A line belongs to one task, so a line already taken — by a task on the
 * schedule, or by another row on this screen — is shown greyed with what has
 * it, rather than hidden. "Why can't I pick this?" is then answered on the
 * spot, and taking it back is a matter of unticking it there.
 *
 * Only labor lines are ever offered here — a material line has no work of
 * its own to schedule, and rides along automatically with whichever labor
 * line installs it (`JobTaskSetupController::pairedMaterialLines()`).
 */
export function TaskLinePicker({
  lines,
  value,
  claimedElsewhere,
  onChange,
  error,
  disabled = false,
}: TaskLinePickerProps) {
  const [isOpen, setIsOpen] = useState(false)
  const containerRef = useClickOutside<HTMLDivElement>(() => setIsOpen(false), isOpen)

  const picked = lines.filter((line) => value.includes(line.id))
  const hours = picked.reduce((sum, line) => sum + line.quantity, 0)
  const worth = picked.reduce((sum, line) => sum + line.total, 0)

  const toggle = (id: number) => {
    onChange(value.includes(id) ? value.filter((it) => it !== id) : [...value, id])
  }

  const blockedBy = (line: EstimateLine): string | null => {
    if (value.includes(line.id)) return null
    if (line.taskTitle) return line.taskTitle
    if (claimedElsewhere.has(line.id)) return 'another task below'

    return null
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        disabled={disabled}
        onClick={() => setIsOpen((open) => !open)}
        aria-expanded={isOpen}
        className={cn(
          'flex w-full items-center justify-between gap-3 rounded-panel border px-4 py-2.5 text-left text-md transition-colors',
          'disabled:cursor-not-allowed disabled:opacity-50',
          error
            ? 'border-status-danger/70 bg-surface-veil/50'
            : 'border-transparent bg-surface-veil/50 hover:border-hairline-strong',
        )}
      >
        <span className={cn('min-w-0 truncate', picked.length ? 'text-white' : 'text-white/75')}>
          {picked.length === 0
            ? 'Pick the estimate lines this task covers'
            : `${picked.length} ${picked.length === 1 ? 'line' : 'lines'} · ${hours} ${
                picked[0]?.unit ?? 'units'
              } · ${formatCurrency(worth, 0)}`}
        </span>
        <ChevronDown size={17} aria-hidden className="shrink-0 text-white/80" />
      </button>

      {isOpen && (
        <div className="absolute z-30 mt-1 max-h-80 w-full overflow-y-auto rounded-panel border border-hairline bg-navy-900 p-1 shadow-panel">
          {lines.length === 0 ? (
            <p className="p-3 text-sm text-white/70">
              This job has no estimate yet, so there are no lines to plan from. Name
              the task instead and add the detail later.
            </p>
          ) : (
            lines.map((line) => {
              const blocked = blockedBy(line)
              const checked = value.includes(line.id)

              return (
                <label
                  key={line.id}
                  className={cn(
                    'flex items-start gap-3 rounded-sm px-3 py-2 text-sm',
                    blocked
                      ? 'cursor-not-allowed opacity-55'
                      : 'cursor-pointer hover:bg-white/10',
                  )}
                >
                  <input
                    type="checkbox"
                    className="mt-0.5 size-4 shrink-0 accent-brand"
                    checked={checked}
                    disabled={Boolean(blocked)}
                    onChange={() => toggle(line.id)}
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-white">{line.description}</span>
                    <span className="text-white/65">
                      {line.quantity}
                      {line.unit ? ` ${line.unit}` : ''} · {formatCurrency(line.total, 0)}
                    </span>
                    {blocked && (
                      <Badge tone="neutral" size="sm" icon={Lock} className="mt-1">
                        In {blocked}
                      </Badge>
                    )}
                  </span>
                </label>
              )
            })
          )}
        </div>
      )}

      {/*
        What is on the task, under the picker it came from. A dropdown closes
        and takes its ticks with it — the list has to be readable, and a line
        removable, without opening it again.
      */}
      {picked.length > 0 && (
        <ul className="mt-3 space-y-2">
          {picked.map((line) => (
            <li
              key={line.id}
              className="flex items-start gap-3 rounded-panel border border-brand/40 bg-brand/8 p-3"
            >
              <span className="min-w-0 flex-1 text-sm">
                <span className="block truncate text-white">{line.description}</span>
                <span className="text-white/70">
                  {line.quantity}
                  {line.unit ? ` ${line.unit}` : ''} · {formatCurrency(line.total, 0)}
                </span>
              </span>
              <IconButton
                icon={X}
                label={`Remove ${line.description}`}
                variant="white"
                size="sm"
                disabled={disabled}
                onClick={() => toggle(line.id)}
                className="shrink-0"
              />
            </li>
          ))}
        </ul>
      )}

      {error && <p className="mt-2 text-sm text-red-300">{error}</p>}
    </div>
  )
}
