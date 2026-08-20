import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/common'
import type { TeamWeekSummaryState } from '@/types'
import { formatHours } from '@/utils'

export interface TeamWeekSummaryProps {
  week: TeamWeekSummaryState
  onPrevWeek: () => void
  onNextWeek: () => void
  onThisWeek: () => void
}

/**
 * One row per person, one column per day — the crew's week at a glance.
 * Every number is the same `time_entries` aggregate the entries table below
 * lists, just grouped differently; there is no second source of truth.
 */
export function TeamWeekSummary({ week, onPrevWeek, onNextWeek, onThisWeek }: TeamWeekSummaryProps) {
  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-xl font-semibold text-white">Weekly Summary</h3>
          <p className="mt-0.5 text-sm text-white/75">Total: {formatHours(week.total)}</p>
        </div>
        <div className="flex items-center gap-2">
          <Button size="sm" variant="secondary" leftIcon={ChevronLeft} onClick={onPrevWeek}>
            Previous
          </Button>
          <Button size="sm" variant="secondary" onClick={onThisWeek}>
            This Week
          </Button>
          <Button size="sm" variant="secondary" rightIcon={ChevronRight} onClick={onNextWeek}>
            Next
          </Button>
        </div>
      </div>

      {week.rows.length === 0 ? (
        <p className="rounded-panel border border-hairline bg-white/4 p-4 text-md text-white/75">
          No time logged this week yet.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-3xl border-separate border-spacing-y-1 text-left">
            <thead>
              <tr>
                <th className="px-4 py-2 text-sm font-semibold text-white/85">Team Member</th>
                {week.days.map((day) => (
                  <th key={day.date} className="px-3 py-2 text-center text-sm font-semibold text-white/85">
                    {day.label}
                  </th>
                ))}
                <th className="px-3 py-2 text-center text-sm font-semibold text-white/85">Total</th>
              </tr>
            </thead>
            <tbody>
              {week.rows.map((row) => (
                <tr key={row.person.id} className="bg-white/6">
                  <td className="rounded-l-panel px-4 py-3 text-md font-medium text-white">
                    {row.person.name}
                  </td>
                  {row.days.map((hours, index) => (
                    <td
                      key={week.days[index]?.date ?? index}
                      className="px-3 py-3 text-center text-md text-white/90 tabular-nums"
                    >
                      {hours > 0 ? formatHours(hours) : '—'}
                    </td>
                  ))}
                  <td className="rounded-r-panel px-3 py-3 text-center text-md font-semibold text-white tabular-nums">
                    {formatHours(row.total)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
