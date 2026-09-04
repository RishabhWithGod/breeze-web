import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ListChecks, PencilLine, Plus, SlidersHorizontal, Trash2 } from 'lucide-react'
import {
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  EmptyState,
  IconButton,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { MOTION, ROUTES, TASK_STATUS_LABEL, TASK_STATUS_TONE, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type { Paginated, TableColumn, TaskStatus } from '@/types'
import { formatHours } from '@/utils'

interface TaskRow {
  readonly id: number
  readonly title: string
  readonly status: TaskStatus
  readonly estimatedHours: number | null
  readonly foreman: string | null
  /** Who is over it. Null for work with a foreman and nobody above them. */
  readonly supervisor: string | null
}

/** One job and the tasks on it, which is the unit this screen pages through. */
interface JobGroup {
  readonly id: number
  readonly name: string
  readonly client: string | null
  readonly tasks: readonly TaskRow[]
}

interface ForemanOption {
  readonly id: number
  readonly name: string
}

export interface TasksProps {
  /**
   * Paged by job, not by task: the screen is grouped by job, and a job with no
   * tasks has to appear so work can be added back to it.
   */
  jobs: Paginated<JobGroup>
  filters: { readonly search: string; readonly status: string; readonly foreman: string }
  statuses: readonly string[]
  foremen: readonly ForemanOption[]
  /** False for anyone who cannot plan work — the list is still readable. */
  canEdit: boolean
}

/** "rough-in" reads as a machine value; the label should not. */
const humanise = (value: string) =>
  value.charAt(0).toUpperCase() + value.slice(1).replaceAll('-', ' ')

/**
 * Every task across every job, grouped under the job it belongs to.
 *
 * The schedule screen answers "what does this job take"; this answers "what is
 * outstanding, and who has it" — the question you have before you know which
 * job you are looking for. Grouping keeps the job and its client stated once
 * per card rather than repeated on every row.
 */
export default function Tasks({ jobs, filters, statuses, foremen, canEdit }: TasksProps) {
  const [search, setSearch] = useState(filters.search)
  const [removing, setRemoving] = useState<TaskRow | null>(null)
  const filterBar = useDisclosure()

  /** How many filters are actually narrowing the list, for the button's badge. */
  const activeFilters = [filters.search !== '', filters.status !== 'all', filters.foreman !== 'all']
    .filter(Boolean).length

  const apply = (changes: Record<string, string>) => {
    const query = new URLSearchParams(window.location.search)

    for (const [key, value] of Object.entries(changes)) {
      if (value === '' || value === 'all') query.delete(key)
      else query.set(key, value)
    }

    // Any change to what is being filtered puts you back on page one; paging
    // itself passes `page` through, so it is set after this and not cleared.
    query.delete('page')
    if (changes['page'] !== undefined) query.set('page', changes['page'])

    router.get(`${ROUTES.tasks}?${query.toString()}`, undefined, {
      preserveState: true,
      replace: true,
    })
  }

  const columns: TableColumn<TaskRow>[] = [
    {
      key: 'title',
      header: 'Task',
      render: (row) => <span className="font-semibold text-white">{row.title}</span>,
    },
    {
      key: 'foreman',
      header: 'Foreman',
      render: (row) => (
        <span className={row.foreman ? 'text-white/90' : 'text-white/60'}>
          {row.foreman ?? 'Unassigned'}
        </span>
      ),
    },
    {
      key: 'supervisor',
      header: 'Supervisor',
      // Optional work, optional column value: plenty of tasks have a foreman
      // and nobody above them.
      render: (row) => (
        <span className={row.supervisor ? 'text-white/90' : 'text-white/60'}>
          {row.supervisor ?? '—'}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <StatusChip
          hideDot
          tone={TASK_STATUS_TONE[row.status]}
          label={TASK_STATUS_LABEL[row.status]}
        />
      ),
    },
    {
      key: 'hours',
      header: 'Hours',
      align: 'right',
      /*
       * The estimate alone. Pairing it with actual hours meant almost every row
       * opened with "0m /", which read as a figure rather than as work that has
       * not started — logged time is on the job and the schedule, where it is
       * entered.
       */
      render: (row) => (
        <span className="whitespace-nowrap tabular-nums text-white/90">
          {row.estimatedHours === null ? '—' : formatHours(row.estimatedHours)}
        </span>
      ),
    },
    ...(canEdit
      ? [
          {
            key: 'actions',
            header: '',
            align: 'right' as const,
            width: 'w-40',
            render: (row: TaskRow) => (
              <span className="flex items-center justify-end gap-1">
                <ButtonLink
                  href={routeTo.taskEdit(row.id)}
                  variant="ghost"
                  size="sm"
                  leftIcon={PencilLine}
                  aria-label={`Edit ${row.title}`}
                >
                  Edit
                </ButtonLink>
                <IconButton
                  icon={Trash2}
                  label={`Remove ${row.title}`}
                  variant="white"
                  size="sm"
                  onClick={() => setRemoving(row)}
                  className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
                />
              </span>
            ),
          },
        ]
      : []),
  ]

  const groups = jobs.data
  const taskCount = groups.reduce((sum, group) => sum + group.tasks.length, 0)

  return (
    <PageTransition>
      <Head title="Tasks" />

      <PageHeader
        title="Tasks"
        subtitle="Every task across every job — what is outstanding, and who has it."
        breadcrumbs={[{ label: 'Jobs', href: ROUTES.jobs }, { label: 'Tasks' }]}
        actions={
          /*
           * Filtering only. Adding a task means saying which job it is on, so
           * it belongs on a job's own card, where the job is already named —
           * a second entry point here only asked the question again.
           */
          <Button
            variant="secondary"
            leftIcon={SlidersHorizontal}
            aria-expanded={filterBar.isOpen}
            onClick={filterBar.toggle}
          >
            Filter{activeFilters > 0 ? ` (${activeFilters})` : ''}
          </Button>
        }
      />

      {/*
        Folded away by default. Most visits to this screen are to read what is
        outstanding, not to narrow it, and three controls across the top were
        pushing the work itself below the fold. The button counts what is
        applied, so a filtered list never looks like the whole one.
      */}
      <AnimatePresence initial={false}>
        {filterBar.isOpen && (
          <motion.div
            key="filter-bar"
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            transition={{ duration: MOTION.base }}
            className="mb-4 overflow-hidden"
          >
            <Card padding="md">
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <SearchBox
                  value={search}
                  onValueChange={setSearch}
                  onSearch={(value) => apply({ search: value })}
                  placeholder="Search tasks…"
                  aria-label="Search tasks"
                />
                <SelectField
                  id="task-status-filter"
                  label="Status"
                  options={[
                    { label: 'All statuses', value: 'all' },
                    ...statuses.map((status) => ({ label: humanise(status), value: status })),
                  ]}
                  value={filters.status}
                  onChange={(event) => apply({ status: event.target.value })}
                />
                <SelectField
                  id="task-foreman-filter"
                  label="Foreman"
                  options={[
                    { label: 'All foremen', value: 'all' },
                    { label: 'Unassigned', value: 'unassigned' },
                    ...foremen.map((man) => ({ label: man.name, value: man.name })),
                  ]}
                  value={filters.foreman}
                  onChange={(event) => apply({ foreman: event.target.value })}
                />
              </div>

              {activeFilters > 0 && (
                <div className="mt-4 border-t border-hairline pt-4">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => {
                      setSearch('')
                      apply({ search: '', status: 'all', foreman: 'all' })
                    }}
                  >
                    Reset filters
                  </Button>
                </div>
              )}
            </Card>
          </motion.div>
        )}
      </AnimatePresence>

      {groups.length === 0 ? (
        <Card padding="lg">
          {/*
            With nothing listed there is no job card to add from, so the way in
            is the job picker — otherwise an empty list is a dead end.
          */}
          <EmptyState
            icon={ListChecks}
            title={activeFilters > 0 ? 'No tasks match' : 'No jobs yet'}
            description={
              activeFilters > 0
                ? 'Nothing on any job matches these filters.'
                : 'Raise a job, and its tasks are laid out from its estimate.'
            }
            {...(activeFilters > 0
              ? {
                  actions: (
                    <Button
                      variant="secondary"
                      onClick={() => {
                        setSearch('')
                        apply({ search: '', status: 'all', foreman: 'all' })
                      }}
                    >
                      Reset filters
                    </Button>
                  ),
                }
              : canEdit
                ? {
                    actions: (
                      <ButtonLink href={ROUTES.taskCreate} leftIcon={Plus}>
                        Add task
                      </ButtonLink>
                    ),
                  }
                : {})}
          />
        </Card>
      ) : (
        <div className="flex flex-col gap-4">
          {groups.map((group) => (
            <Card key={group.id} padding="lg">
              <header className="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-hairline pb-3">
                <div className="min-w-0">
                  <Link
                    href={routeTo.jobFrom(group.id, 'tasks')}
                    className="text-lg font-semibold text-white transition-colors hover:text-brand"
                  >
                    {group.name}
                  </Link>
                  {/* Stated once per job rather than repeated on every row. */}
                  <p className="mt-0.5 truncate text-sm text-white/70">
                    {group.client ?? 'No client on this job'}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-3">
                  <span className="text-sm text-white/70">
                    {group.tasks.length} {group.tasks.length === 1 ? 'task' : 'tasks'}
                  </span>
                  {/*
                    Adding to *this* job, so it skips the job picker and opens
                    the same step that laid the job out in the first place —
                    with the tasks already on it listed, and its estimate lines
                    to build the new ones from.
                  */}
                  {canEdit && (
                    <ButtonLink
                      href={routeTo.jobTaskSetupFromList(group.id)}
                      variant="secondary"
                      size="sm"
                      leftIcon={Plus}
                    >
                      Add task
                    </ButtonLink>
                  )}
                </div>
              </header>

              {group.tasks.length === 0 ? (
                <p className="text-md text-white/70">
                  No tasks on this job yet.
                  {canEdit && ' Add the work it takes above.'}
                </p>
              ) : (
                <Table
                  columns={columns}
                  rows={group.tasks}
                  getRowId={(row) => row.id}
                  variant="lined"
                  caption={`Tasks on ${group.name}`}
                />
              )}
            </Card>
          ))}
        </div>
      )}

      <Pagination
        withLabels
        className="mt-6"
        page={jobs.meta.current_page}
        pageCount={jobs.meta.last_page}
        onPageChange={(page) => apply({ page: String(page) })}
        summary={
          jobs.meta.total === 0
            ? 'No jobs to display'
            : `${taskCount} ${taskCount === 1 ? 'task' : 'tasks'} across ${groups.length} of ${jobs.meta.total} jobs`
        }
      />

      <ConfirmDialog
        isOpen={removing !== null}
        tone="danger"
        title={`Remove “${removing?.title ?? ''}”?`}
        description="The estimate lines it covers go back to be planned into another task, and the job's hours are recalculated."
        confirmLabel="Remove task"
        confirmVariant="danger"
        onConfirm={() => {
          if (removing) router.delete(routeTo.taskRemove(removing.id), { preserveScroll: true })
          setRemoving(null)
        }}
        onCancel={() => setRemoving(null)}
      />
    </PageTransition>
  )
}

Tasks.layout = appLayout
