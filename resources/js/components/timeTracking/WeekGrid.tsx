import { ProgressBar } from '@/components/common'
import type { TimesheetWeek } from '@/types'
import { cn, formatHours } from '@/utils'

export interface WeekGridProps {
  week: TimesheetWeek
}

/**
 * The weekly timesheet: one column per day, regular/overtime/billable at a
 * glance, and the week's totals. Every number is the server's own — nothing
 * here is re-derived from the entries themselves.
 */
export function WeekGrid({ week }: WeekGridProps) {
  const busiest = Math.max(8, ...week.days.map((day) => day.total))

  return (
    <div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
        {week.days.map((day) => (
          <div
            key={day.date}
            className={cn(
              'rounded-panel border p-4',
              day.isToday
                ? 'border-brand/60 bg-brand/10'
                : 'border-hairline bg-white/4',
            )}
          >
            <p
              className={cn(
                'text-xs font-semibold tracking-wide uppercase',
                day.isToday ? 'text-brand' : 'text-white/70',
              )}
            >
              {day.label}
            </p>
            <p className="mt-1 text-2xl font-bold text-white">
              {day.total > 0 ? formatHours(day.total) : '—'}
            </p>

            <ProgressBar
              className="mt-3"
              size="sm"
              value={(day.total / busiest) * 100}
              tone={day.overtime > 0 ? 'warning' : 'brand'}
            />

            <dl className="mt-3 space-y-1 text-sm text-white/80">
              <div className="flex justify-between">
                <dt>Regular</dt>
                <dd className="tabular-nums text-white">{formatHours(day.regular)}</dd>
              </div>
              {day.overtime > 0 && (
                <div className="flex justify-between text-status-warning">
                  <dt>Overtime</dt>
                  <dd className="tabular-nums">{formatHours(day.overtime)}</dd>
                </div>
              )}
              <div className="flex justify-between">
                <dt>Billable</dt>
                <dd className="tabular-nums text-white">{formatHours(day.billable)}</dd>
              </div>
            </dl>
          </div>
        ))}
      </div>

      <div className="mt-5 grid grid-cols-2 gap-4 rounded-panel border border-hairline bg-white/6 p-4 sm:grid-cols-4">
        <TotalStat label="Total" value={formatHours(week.totals.total)} />
        <TotalStat label="Regular" value={formatHours(week.totals.regular)} />
        <TotalStat
          label="Overtime"
          value={formatHours(week.totals.overtime)}
          tone={week.totals.overtime > 0 ? 'text-status-warning' : undefined}
        />
        <TotalStat label="Billable" value={formatHours(week.totals.billable)} />
      </div>
    </div>
  )
}

function TotalStat({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div>
      <p className="text-xs tracking-wide text-white/70 uppercase">{label}</p>
      <p className={cn('mt-1 text-xl font-bold text-white', tone)}>{value}</p>
    </div>
  )
}
