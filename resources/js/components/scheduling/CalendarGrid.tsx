import { useMemo } from 'react'
import { Plus } from 'lucide-react'
import { WEEKDAY_HEADINGS } from '@/constants'
import type { CalendarDay, JobShift } from '@/types'
import { cn } from '@/utils'
import { ShiftBlock } from './ShiftBlock'

export interface CalendarGridProps {
  days: readonly CalendarDay[]
  shifts: readonly JobShift[]
  onSelectShift: (shift: JobShift) => void
  onRemoveShift: (shift: JobShift) => void
  /** Opens the assign form pre-filled with the day that was clicked. */
  onAddOnDay: (date: string) => void
  className?: string
}

/**
 * The calendar itself: seven columns, one row per week.
 *
 * A real table rather than a grid of divs — this is tabular data with day headers,
 * and a screen reader should be able to say "Wednesday, October 4" for a cell. The
 * server hands over both the day list and the shifts, so this component only has to
 * bucket one by the other; it never does date arithmetic of its own.
 */
export function CalendarGrid({
  days,
  shifts,
  onSelectShift,
  onRemoveShift,
  onAddOnDay,
  className,
}: CalendarGridProps) {
  /** Shifts keyed by day, so each cell is a lookup instead of a scan. */
  const byDay = useMemo(() => {
    const map = new Map<string, JobShift[]>()

    for (const shift of shifts) {
      const bucket = map.get(shift.date)
      if (bucket) bucket.push(shift)
      else map.set(shift.date, [shift])
    }

    return map
  }, [shifts])

  const weeks = useMemo(() => {
    const rows: CalendarDay[][] = []

    for (let index = 0; index < days.length; index += 7) {
      rows.push(days.slice(index, index + 7))
    }

    return rows
  }, [days])

  return (
    <div className={cn('overflow-x-auto', className)}>
      <table className="w-full min-w-[860px] table-fixed border-collapse">
        <caption className="sr-only">
          Crew schedule. Each cell lists the shifts booked for that day.
        </caption>
        <thead>
          <tr>
            {WEEKDAY_HEADINGS.map((heading) => (
              <th
                key={heading}
                scope="col"
                className="border border-hairline bg-white/8 px-3 py-3 text-center text-md font-semibold text-white"
              >
                {heading}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {weeks.map((week) => (
            <tr key={week[0]?.date ?? 'week'}>
              {week.map((day) => {
                const dayShifts = byDay.get(day.date) ?? []

                return (
                  <td
                    key={day.date}
                    className={cn(
                      'group/day h-40 border border-hairline align-top transition-colors',
                      day.isCurrentPeriod ? 'bg-white/5' : 'bg-transparent',
                      day.isWeekend && 'bg-navy-950/25',
                      day.isToday && 'ring-1 ring-brand/60 ring-inset',
                    )}
                  >
                    <div className="flex h-full flex-col gap-1.5 p-2">
                      <div className="flex items-center justify-between">
                        <span
                          className={cn(
                            'text-sm font-semibold',
                            day.isToday
                              ? 'grid size-6 place-items-center rounded-full bg-brand text-brand-ink'
                              : day.isCurrentPeriod
                                ? 'text-white'
                                : 'text-white/35',
                          )}
                        >
                          {day.dayOfMonth}
                        </span>

                        {/* Keyboard-reachable, not hover-only: this is the primary
                            way a shift gets added to a specific day. */}
                        <button
                          type="button"
                          onClick={() => onAddOnDay(day.date)}
                          aria-label={`Assign a crew on ${day.label}`}
                          className={cn(
                            'rounded-full p-1 text-white/50 opacity-0 transition',
                            'hover:bg-brand/25 hover:text-white',
                            'group-hover/day:opacity-100 focus-visible:opacity-100 focus-visible:outline-none',
                          )}
                        >
                          <Plus size={13} aria-hidden />
                        </button>
                      </div>

                      <div className="flex min-h-0 flex-1 flex-col gap-1.5 overflow-y-auto">
                        {dayShifts.map((shift) => (
                          <ShiftBlock
                            key={shift.id}
                            shift={shift}
                            onSelect={onSelectShift}
                            onRemove={onRemoveShift}
                            muted={!day.isCurrentPeriod}
                          />
                        ))}
                      </div>
                    </div>
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
