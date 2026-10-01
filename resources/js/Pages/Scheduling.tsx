import { useMemo, useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import {
  AlertTriangle,
  Check,
  ChevronLeft,
  ChevronRight,
  FileText,
  MapPin,
  Search,
  Clock,
} from 'lucide-react'
import { Alert, EmptyState } from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type {
  BoardBlock,
  BoardCrew,
  BoardDay,
  BoardState,
  BoardUnassignedJob,
  CalendarView,
  CrewBoardData,
  SharedPageProps,
} from '@/types'
import { cn } from '@/utils'

export interface SchedulingProps {
  /** Any day inside the visible window. */
  anchor: string
  today: string
  board: CrewBoardData
}

const VIEWS: readonly { value: CalendarView; label: string }[] = [
  { value: 'day', label: 'Day' },
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
]

/** Crossed stripes, for the two states that want a person's eye. */
const HATCH = (color: string) =>
  ({
    backgroundImage: `repeating-linear-gradient(135deg, ${color} 0 6px, transparent 6px 12px)`,
  }) as const

const STATE_STYLE: Record<BoardState, { box: string; icon: typeof FileText; label: string }> = {
  scheduled: { box: 'border-blue-300/40 bg-blue-500 text-white', icon: FileText, label: 'Scheduled' },
  'in-progress': { box: 'border-cyan-200/40 bg-cyan-600 text-white', icon: Clock, label: 'In progress' },
  completed: { box: 'border-emerald-200/40 bg-emerald-500 text-white', icon: Check, label: 'Completed' },
  conflict: {
    box: 'border-red-400/70 bg-red-950/50 text-white',
    icon: AlertTriangle,
    label: 'Conflict — the crew has more than one job this day',
  },
  attention: {
    box: 'border-amber-400/70 bg-amber-950/50 text-white',
    icon: AlertTriangle,
    label: 'Requires attention — the job is delayed',
  },
}

const PRIORITY: Record<string, { stripe: string; chip: string; label: string }> = {
  high: { stripe: 'bg-red-500', chip: 'bg-red-500 text-white', label: 'High' },
  medium: { stripe: 'bg-amber-400', chip: 'bg-amber-400 text-navy-950', label: 'Medium' },
  low: { stripe: 'bg-blue-500', chip: 'bg-blue-500 text-white', label: 'Low' },
}

/**
 * Job Scheduling — every crew's jobs, by day.
 *
 * Nothing is booked here. A job sits on its crew's row for the days between its
 * start and end dates, and appears once it has tasks. Jobs with no tasks yet wait
 * in the list beside it.
 *
 * The window lives in the query string, so paging is a server visit and the URL is
 * shareable; the server sends the days already labelled, so the browser's timezone
 * never moves a job onto the wrong day.
 */
export default function Scheduling({ anchor, today, board }: SchedulingProps) {
  const { flash } = usePage<SharedPageProps>().props
  const { view } = board

  const goTo = (next: Partial<{ view: CalendarView; date: string }>) => {
    router.get(
      ROUTES.schedulingCalendar,
      { view: next.view ?? view, date: next.date ?? anchor },
      { preserveScroll: true, preserveState: true, replace: true },
    )
  }

  /** One period back or forward, worked from the date the server sent. */
  const step = (direction: -1 | 1) => {
    const base = new Date(`${anchor}T00:00:00`)

    if (view === 'month') {
      // Anchored on the 15th: stepping from a 31st would skip a short month.
      base.setDate(15)
      base.setMonth(base.getMonth() + direction)
    } else {
      base.setDate(base.getDate() + direction * (view === 'day' ? 1 : 7))
    }

    const pad = (value: number) => String(value).padStart(2, '0')
    goTo({ date: `${base.getFullYear()}-${pad(base.getMonth() + 1)}-${pad(base.getDate())}` })
  }

  // Narrowing to one crew is a view of the same board, not another request.
  const [crewFilter, setCrewFilter] = useState('all')

  const visibleBlocks = useMemo(
    () => (crewFilter === 'all' ? board.blocks : board.blocks.filter((block) => String(block.crewId) === crewFilter)),
    [board.blocks, crewFilter],
  )
  const visibleCrews = useMemo(
    () => (crewFilter === 'all' ? board.crews : board.crews.filter((crew) => String(crew.id) === crewFilter)),
    [board.crews, crewFilter],
  )

  const blocksByCell = useMemo(() => {
    const cells = new Map<string, BoardBlock[]>()

    for (const block of visibleBlocks) {
      const key = `${block.crewId}|${block.date}`
      cells.set(key, [...(cells.get(key) ?? []), block])
    }

    return cells
  }, [visibleBlocks])

  /** What this window holds, said once above it rather than counted by eye. */
  const summary = useMemo(() => {
    const clashes = new Set(
      visibleBlocks
        .filter((block) => block.state === 'conflict')
        .map((block) => `${block.crewId}|${block.date}`),
    )

    return {
      jobs: new Set(visibleBlocks.map((block) => block.jobId)).size,
      clashes: clashes.size,
    }
  }, [visibleBlocks])

  return (
    <PageTransition>
      <Head title="Job Scheduling" />

      <header className="mb-5">
        <h1 className="text-3xl font-bold text-white">Job Scheduling</h1>
        <p className="mt-1 text-md text-white/85">
          Every crew&apos;s jobs by day — placed automatically from each job&apos;s crew and dates.
        </p>
      </header>

      {flash?.success && (
        <Alert tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}
      {flash?.warning && (
        <Alert tone="warning" className="mb-4">
          {flash.warning}
        </Alert>
      )}

      {/* ===================================================== Toolbar ========= */}
      <div className="mb-4 flex flex-wrap items-center justify-between gap-4 rounded-card border border-hairline glass px-4 py-3">
        <div className="flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={() => goTo({ date: today })}
            className="rounded-panel border border-hairline-strong bg-white/8 px-5 py-2.5 text-md font-semibold text-white transition-colors hover:bg-white/15"
          >
            Today
          </button>
          <div className="flex items-center gap-1.5">
            <NavButton label={`Previous ${view}`} onClick={() => step(-1)} icon={ChevronLeft} />
            <NavButton label={`Next ${view}`} onClick={() => step(1)} icon={ChevronRight} />
          </div>
          <h2 className="ml-2 text-lg font-semibold text-white">{board.label}</h2>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <span className="rounded-full border border-hairline bg-white/6 px-3 py-1.5 text-xs text-white/90">
            <strong className="font-semibold text-white">{summary.jobs}</strong>{' '}
            {summary.jobs === 1 ? 'job' : 'jobs'}
          </span>
          {summary.clashes > 0 && (
            <span className="rounded-full border border-red-400/50 bg-red-500/15 px-3 py-1.5 text-xs text-red-200">
              <strong className="font-semibold">{summary.clashes}</strong>{' '}
              {summary.clashes === 1 ? 'clash' : 'clashes'}
            </span>
          )}

          <select
            aria-label="Crew"
            value={crewFilter}
            onChange={(event) => setCrewFilter(event.target.value)}
            className="rounded-panel border border-hairline-strong bg-white/8 px-3 py-2.5 text-md text-white focus:border-brand focus:outline-none"
          >
            <option value="all" className="bg-navy-900">All crews</option>
            {board.crews.map((crew) => (
              <option key={crew.id} value={crew.id} className="bg-navy-900">
                {crew.name}
              </option>
            ))}
          </select>

        <div
          className="inline-flex overflow-hidden rounded-panel border border-hairline-strong"
          role="group"
          aria-label="Calendar view"
        >
          {VIEWS.map((option) => (
            <button
              key={option.value}
              type="button"
              aria-pressed={view === option.value}
              onClick={() => goTo({ view: option.value })}
              className={cn(
                'px-5 py-2.5 text-md font-semibold transition-colors',
                view === option.value
                  ? 'bg-blue-600 text-white'
                  : 'bg-white/6 text-white/85 hover:bg-white/12',
              )}
            >
              {option.label}
            </button>
          ))}
        </div>
        </div>
      </div>

      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
        <div className="min-w-0 space-y-4">
          {view === 'month' ? (
            <MonthGrid
              days={board.days}
              crews={board.crews}
              blocks={visibleBlocks}
              onOpenDay={(date) => goTo({ view: 'day', date })}
            />
          ) : (
            <CrewGrid days={board.days} crews={visibleCrews} cells={blocksByCell} />
          )}

          <Legend />
        </div>

        <UnassignedPanel jobs={board.unassigned} total={board.unassignedTotal} />
      </div>
    </PageTransition>
  )
}

Scheduling.layout = appLayout

function NavButton({
  label,
  onClick,
  icon: Icon,
}: {
  label: string
  onClick: () => void
  icon: typeof ChevronLeft
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className="grid size-10 place-items-center rounded-panel border border-hairline-strong bg-white/8 text-white transition-colors hover:bg-white/15"
    >
      <Icon size={18} aria-hidden />
    </button>
  )
}

/* ------------------------------------------------------------------ blocks */

function Block({ block, compact = false }: { block: BoardBlock; compact?: boolean }) {
  const style = STATE_STYLE[block.state]
  const Icon = style.icon
  const hatched = block.state === 'conflict' || block.state === 'attention'

  return (
    <Link
      href={routeTo.jobFrom(block.jobId, 'scheduling-calendar')}
      title={`${block.name}${block.location ? ` · ${block.location}` : ''} — ${style.label}`}
      className={cn(
        'relative block overflow-hidden rounded-panel border px-2.5 py-1.5 text-left shadow-sm transition-[filter,transform] hover:-translate-y-px hover:brightness-110',
        style.box,
      )}
      {...(hatched ? { style: HATCH(block.state === 'conflict' ? 'rgb(239 68 68 / 0.28)' : 'rgb(251 191 36 / 0.25)') } : {})}
    >
      {!compact && block.time && <p className="truncate text-2xs text-white/90">{block.time}</p>}
      <p className="truncate pr-5 text-xs font-semibold">{block.name}</p>
      {!compact && block.location && (
        <p className="truncate pr-5 text-2xs text-white/80">{block.location}</p>
      )}
      <span className="absolute right-2 bottom-2 text-white/90" aria-hidden>
        {block.state === 'completed' ? (
          <span className="grid size-4 place-items-center rounded-full border border-white/80">
            <Check size={10} strokeWidth={3} />
          </span>
        ) : block.state === 'conflict' || block.state === 'attention' ? (
          <span className="grid size-4 place-items-center rounded-full bg-red-500 text-white">
            <span className="text-2xs leading-none font-bold">!</span>
          </span>
        ) : (
          <Icon size={13} />
        )}
      </span>
    </Link>
  )
}

/* ------------------------------------------------------------ week / day */

/**
 * The board scrolls inside its own frame, sized to the window rather than to its
 * content: a crew with no jobs does not leave a gap, and a busy week does not
 * push the page down. The day header and the crew column stay put while it does.
 */
const FRAME = 'max-h-[calc(100dvh-17rem)] min-h-[26rem] overflow-auto rounded-card border border-hairline glass'

function CrewGrid({
  days,
  crews,
  cells,
}: {
  days: readonly BoardDay[]
  crews: readonly BoardCrew[]
  cells: ReadonlyMap<string, BoardBlock[]>
}) {
  const single = days.length === 1
  const columns = `12rem repeat(${days.length}, minmax(${single ? '20rem' : '10rem'}, 1fr))`

  if (crews.length === 0) {
    return (
      <div className="rounded-card border border-hairline glass p-6">
        <EmptyState
          title="No crews yet"
          description="Crews appear here once a team has been created and given jobs."
        />
      </div>
    )
  }

  return (
    <div className={FRAME}>
      <div className="min-w-max" style={{ display: 'grid', gridTemplateColumns: columns }}>
        <div className="sticky top-0 left-0 z-30 flex items-center border-r border-b border-hairline bg-navy-900 px-4 py-3 text-md font-bold text-white">
          Crews
        </div>
        {days.map((day) => (
          <DayHeader key={day.date} day={day} />
        ))}

        {crews.map((crew) => (
          <CrewRow key={crew.id} crew={crew} days={days} cells={cells} />
        ))}
      </div>
    </div>
  )
}

function DayHeader({ day }: { day: BoardDay }) {
  return (
    <div
      className={cn(
        'sticky top-0 z-20 border-b border-l border-hairline px-3 py-2.5 text-center',
        day.isToday ? 'bg-blue-700' : day.isWeekend ? 'bg-navy-900' : 'bg-navy-800',
      )}
    >
      <p className={cn('text-xs font-semibold tracking-wide uppercase', day.isWeekend && !day.isToday ? 'text-white/60' : 'text-white/85')}>
        {day.weekday}
      </p>
      <p className="text-md font-semibold text-white">{day.monthDay}</p>
    </div>
  )
}

function CrewRow({
  crew,
  days,
  cells,
}: {
  crew: BoardCrew
  days: readonly BoardDay[]
  cells: ReadonlyMap<string, BoardBlock[]>
}) {
  const jobCount = new Set(
    days.flatMap((day) => (cells.get(`${crew.id}|${day.date}`) ?? []).map((block) => block.jobId)),
  ).size

  return (
    <>
      <div className="sticky left-0 z-10 flex items-center gap-3 border-r border-b border-hairline bg-navy-900 px-4 py-3">
        <span
          className={cn('size-3 shrink-0 rounded-full', jobCount > 0 ? 'bg-status-success' : 'bg-white/30')}
          aria-hidden
        />
        <div className="min-w-0">
          <p className="truncate text-md font-semibold text-white">{crew.name}</p>
          <p className="text-xs text-white/70">
            {crew.memberCount} {crew.memberCount === 1 ? 'member' : 'members'} · {jobCount}{' '}
            {jobCount === 1 ? 'job' : 'jobs'}
          </p>
        </div>
      </div>

      {days.map((day) => {
        const blocks = cells.get(`${crew.id}|${day.date}`) ?? []

        return (
          <div
            key={day.date}
            className={cn(
              'flex min-h-20 flex-col gap-1.5 border-b border-l border-hairline p-1.5',
              day.isToday && 'bg-blue-600/10',
              day.isWeekend && 'bg-black/15',
            )}
          >
            {blocks.map((block) => (
              <Block key={block.key} block={block} />
            ))}
          </div>
        )
      })}
    </>
  )
}

/* ----------------------------------------------------------------- month */

const MAX_PER_DAY = 4

/** A job on a month cell: one line, its state as a dot — the week view has the detail. */
function MonthChip({ block, crewName }: { block: BoardBlock; crewName: string | undefined }) {
  const style = STATE_STYLE[block.state]
  const tone =
    block.state === 'completed'
      ? 'bg-emerald-400'
      : block.state === 'conflict'
        ? 'bg-red-400'
        : block.state === 'attention'
          ? 'bg-amber-400'
          : block.state === 'in-progress'
            ? 'bg-cyan-400'
            : 'bg-blue-400'

  return (
    <Link
      href={routeTo.jobFrom(block.jobId, 'scheduling-calendar')}
      title={`${block.name}${crewName ? ` · ${crewName}` : ''} — ${style.label}`}
      className="flex items-center gap-1.5 rounded-sm bg-white/8 px-1.5 py-1 text-2xs leading-tight text-white transition-colors hover:bg-white/18"
    >
      <span className={cn('size-2 shrink-0 rounded-full', tone)} aria-hidden />
      <span className="truncate font-medium">{block.name}</span>
      {crewName && <span className="ml-auto shrink-0 truncate text-white/60">{crewName}</span>}
    </Link>
  )
}

function MonthGrid({
  days,
  crews,
  blocks,
  onOpenDay,
}: {
  days: readonly BoardDay[]
  crews: readonly BoardCrew[]
  blocks: readonly BoardBlock[]
  onOpenDay: (date: string) => void
}) {
  const crewName = useMemo(() => new Map(crews.map((crew) => [crew.id, crew.name])), [crews])

  const byDate = useMemo(() => {
    const map = new Map<string, BoardBlock[]>()

    for (const block of blocks) {
      map.set(block.date, [...(map.get(block.date) ?? []), block])
    }

    return map
  }, [blocks])

  return (
    <div className={FRAME}>
      <div className="grid min-w-3xl grid-cols-7">
        {days.slice(0, 7).map((day) => (
          <div
            key={day.weekday}
            className="sticky top-0 z-10 border-b border-hairline bg-navy-800 px-3 py-2.5 text-center text-xs font-semibold tracking-wide text-white/90 uppercase"
          >
            {day.weekday}
          </div>
        ))}

        {days.map((day) => {
          const dayBlocks = byDate.get(day.date) ?? []

          return (
            <div
              key={day.date}
              className={cn(
                'flex min-h-32 flex-col gap-1 border-b border-l border-hairline p-1.5',
                !day.isCurrentPeriod && 'bg-black/20 opacity-50',
                day.isWeekend && day.isCurrentPeriod && 'bg-black/15',
                day.isToday && 'bg-blue-600/15',
              )}
            >
              <div className="flex items-center justify-between">
                <button
                  type="button"
                  onClick={() => onOpenDay(day.date)}
                  className={cn(
                    'grid size-7 place-items-center rounded-full text-xs font-semibold transition-colors hover:bg-white/15',
                    day.isToday ? 'bg-blue-600 text-white' : 'text-white/85',
                  )}
                  aria-label={`Open ${day.monthDay}`}
                >
                  {day.dayOfMonth}
                </button>
                {dayBlocks.length > 0 && (
                  <span className="text-2xs text-white/55">{dayBlocks.length}</span>
                )}
              </div>

              {dayBlocks.slice(0, MAX_PER_DAY).map((block) => (
                <MonthChip key={block.key} block={block} crewName={crewName.get(block.crewId)} />
              ))}

              {dayBlocks.length > MAX_PER_DAY && (
                <button
                  type="button"
                  onClick={() => onOpenDay(day.date)}
                  className="text-left text-2xs font-medium text-brand hover:text-white"
                >
                  +{dayBlocks.length - MAX_PER_DAY} more
                </button>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

/* ---------------------------------------------------------------- legend */

function Legend() {
  const items: readonly { label: string; swatch: React.ReactNode }[] = [
    { label: 'Scheduled', swatch: <span className="size-3.5 rounded-sm bg-blue-500" /> },
    { label: 'In Progress', swatch: <span className="size-3.5 rounded-sm bg-cyan-600" /> },
    {
      label: 'Completed',
      swatch: (
        <span className="grid size-4 place-items-center rounded-full bg-emerald-500 text-white">
          <Check size={10} strokeWidth={3} />
        </span>
      ),
    },
    {
      label: 'Conflict',
      swatch: (
        <span
          className="size-4 rounded-sm border border-red-400/70 bg-red-950/60"
          style={HATCH('rgb(239 68 68 / 0.45)')}
        />
      ),
    },
    {
      label: 'Requires Attention',
      swatch: (
        <span
          className="size-4 rounded-sm border border-amber-400/70 bg-amber-950/60"
          style={HATCH('rgb(251 191 36 / 0.4)')}
        />
      ),
    },
  ]

  return (
    <ul className="flex flex-wrap items-center gap-x-7 gap-y-2 rounded-card border border-hairline glass px-5 py-3.5">
      {items.map((item) => (
        <li key={item.label} className="flex items-center gap-2.5 text-sm text-white/90">
          {item.swatch}
          {item.label}
        </li>
      ))}
    </ul>
  )
}

/* ------------------------------------------------------------ unassigned */

function UnassignedPanel({
  jobs,
  total,
}: {
  jobs: readonly BoardUnassignedJob[]
  total: number
}) {
  const [query, setQuery] = useState('')
  const [type, setType] = useState('all')
  const [priority, setPriority] = useState('all')

  const shown = jobs.filter(
    (job) =>
      (type === 'all' || job.type === type) &&
      (priority === 'all' || job.priority === priority) &&
      (query.trim() === '' ||
        `${job.name} ${job.client ?? ''} ${job.location ?? ''}`
          .toLowerCase()
          .includes(query.trim().toLowerCase())),
  )

  const selectClass =
    'w-full appearance-none rounded-panel border border-hairline-strong bg-white/8 px-3 py-2 text-sm text-white focus:border-brand focus:outline-none'

  return (
    <aside className="rounded-card border border-hairline glass p-4 xl:sticky xl:top-4 xl:max-h-[calc(100dvh-7rem)] xl:overflow-y-auto">
      <h2 className="text-lg font-semibold text-white">Unassigned Jobs ({total})</h2>
      <p className="mt-1 text-xs text-white/70">
        Jobs with no tasks yet. Add tasks to a job and it appears on the calendar.
      </p>

      <label className="relative mt-3 block">
        <span className="sr-only">Search jobs</span>
        <Search size={15} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-white/60" />
        <input
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search jobs..."
          className="w-full rounded-full border border-hairline-strong bg-white/8 py-2 pr-3 pl-9 text-sm text-white placeholder:text-white/55 focus:border-brand focus:outline-none"
        />
      </label>

      <div className="mt-3 grid grid-cols-2 gap-2">
        <select aria-label="Type" value={type} onChange={(event) => setType(event.target.value)} className={selectClass}>
          <option value="all" className="bg-navy-900">All Types</option>
          <option value="residential" className="bg-navy-900">Residential</option>
          <option value="commercial" className="bg-navy-900">Commercial</option>
          <option value="industrial" className="bg-navy-900">Industrial</option>
        </select>
        <select aria-label="Priority" value={priority} onChange={(event) => setPriority(event.target.value)} className={selectClass}>
          <option value="all" className="bg-navy-900">All Priority</option>
          <option value="high" className="bg-navy-900">High</option>
          <option value="medium" className="bg-navy-900">Medium</option>
          <option value="low" className="bg-navy-900">Low</option>
        </select>
      </div>

      {shown.length === 0 ? (
        <p className="mt-5 rounded-panel border border-dashed border-hairline-strong p-4 text-center text-sm text-white/75">
          {jobs.length === 0 ? 'Every job has tasks.' : 'No jobs match.'}
        </p>
      ) : (
        <ul className="mt-3 space-y-2">
          {shown.map((job) => {
            const tone = PRIORITY[job.priority] ?? PRIORITY['medium']!

            return (
              <li key={job.id}>
                <Link
                  href={routeTo.jobFrom(job.id, 'scheduling-calendar')}
                  className="relative flex items-center gap-3 overflow-hidden rounded-panel border border-hairline bg-white/5 py-2.5 pr-3 pl-4 transition-colors hover:border-brand/50 hover:bg-white/10"
                >
                  <span className={cn('absolute inset-y-0 left-0 w-1.5', tone.stripe)} aria-hidden />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold text-white">{job.name}</span>
                    <span className="block truncate text-xs text-white/70 capitalize">
                      {job.type ?? 'No type'}
                    </span>
                    <span className="mt-0.5 flex items-center gap-1 truncate text-xs text-white/70">
                      <MapPin size={11} aria-hidden className="shrink-0" />
                      <span className="truncate">{job.location ?? 'No location'}</span>
                    </span>
                  </span>
                  <span className="flex shrink-0 flex-col items-end gap-1.5">
                    {job.hours !== null && <span className="text-xs text-white/80">{job.hours}h</span>}
                    <span className={cn('rounded-sm px-2 py-0.5 text-2xs font-semibold', tone.chip)}>
                      {tone.label}
                    </span>
                  </span>
                </Link>
              </li>
            )
          })}
        </ul>
      )}

      {total > jobs.length && (
        <Link href={ROUTES.scheduling} className="mt-3 block text-sm font-medium text-brand hover:text-white">
          View all {total} →
        </Link>
      )}
    </aside>
  )
}
