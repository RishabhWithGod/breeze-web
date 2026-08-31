import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { CalendarDays, SearchX } from 'lucide-react'
import {
  Alert,
  Button,
  Card,
  EmptyState,
  FilterTabs,
  Pagination,
  SearchBox,
  SelectField,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { AssignCrewModal, UnassignedJobRow } from '@/components/scheduling'
import {
  ROUTES,
  SCHEDULING_SORT_OPTIONS,
  SCHEDULING_TYPE_FILTERS,
  type SchedulingSort,
  type SchedulingTypeFilter,
} from '@/constants'
import type {
  CrewMember,
  Paginated,
  SchedulableJob,
  SharedPageProps,
} from '@/types'

interface UnassignedFilters {
  search: string
  type: SchedulingTypeFilter
  sort: SchedulingSort
}

export interface SchedulingUnassignedProps {
  jobs: Paginated<SchedulableJob>
  filters: UnassignedFilters
  counts: Record<SchedulingTypeFilter, number>
  crews: readonly string[]
  members: readonly CrewMember[]
  today: string
}

/**
 * Unassigned Jobs — everything still waiting for a crew.
 *
 * "Unassigned" is the absence of a booking on the calendar, not a status, so
 * scheduling a job here is what removes it from this list. Search, tabs, sorting
 * and paging all run in the database against the query string, which keeps a
 * filtered queue shareable and survives the visit each change triggers.
 */
export default function SchedulingUnassigned({
  jobs,
  filters,
  counts,
  crews,
  members,
  today,
}: SchedulingUnassignedProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [scheduling, setScheduling] = useState<SchedulableJob | null>(null)

  /** Every filter change is one visit, with the whole filter set carried over. */
  const apply = useCallback(
    (next: Partial<UnassignedFilters & { page: number }>) => {
      router.get(
        ROUTES.scheduling,
        {
          ...(next.search ?? filters.search ? { search: next.search ?? filters.search } : {}),
          type: next.type ?? filters.type,
          sort: next.sort ?? filters.sort,
          ...(next.page && next.page > 1 ? { page: next.page } : {}),
        },
        { preserveScroll: true, preserveState: true, replace: true },
      )
    },
    [filters.search, filters.type, filters.sort],
  )

  const isFiltered = filters.search !== '' || filters.type !== 'all'

  return (
    <PageTransition>
      <Head title="Unassigned Jobs" />

      <PageHeader
        title="Unassigned Jobs"
        subtitle="Jobs that need to be scheduled on the calendar"
        actions={
          <>
            <SearchBox
              value={query}
              onValueChange={setQuery}
              onSearch={(value) => apply({ search: value })}
              placeholder="Search clients..."
              containerClassName="w-full sm:w-72"
              aria-label="Search unassigned jobs"
            />
            <Button
              variant="dark"
              leftIcon={CalendarDays}
              onClick={() => router.get(ROUTES.schedulingCalendar)}
            >
              Go to Calendar
            </Button>
          </>
        }
      />

      {flash?.success && (
        <Alert tone="success" className="mb-6">
          {flash.success}
        </Alert>
      )}

      <Card padding="md" className="mb-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          {/*
            The count is written into the label — "All Jobs (24)" — rather than
            passed as a separate badge, which is how the reference reads it.
          */}
          <FilterTabs
            solid
            options={SCHEDULING_TYPE_FILTERS.map((option) => ({
              label: `${option.label} (${counts[option.value] ?? 0})`,
              value: option.value,
            }))}
            value={filters.type}
            onChange={(type) => apply({ type, page: 1 })}
          />

          <div className="flex items-center gap-3">
            <label
              htmlFor="unassigned-sort"
              className="shrink-0 text-md font-medium text-white/90"
            >
              Sort by:
            </label>
            <SelectField
              id="unassigned-sort"
              value={filters.sort}
              onChange={(event) => apply({ sort: event.target.value as SchedulingSort, page: 1 })}
              options={SCHEDULING_SORT_OPTIONS}
              className="min-w-60"
            />
          </div>
        </div>
      </Card>

      {jobs.data.length === 0 ? (
        <EmptyState
          icon={SearchX}
          title={isFiltered ? 'No jobs match those filters' : 'Nothing left to schedule'}
          description={
            isFiltered
              ? 'Try a different search or clear the type filter.'
              : 'Every active job has a crew booked on the calendar.'
          }
          actions={
            isFiltered ? (
              <Button
                variant="secondary"
                onClick={() => {
                  setQuery('')
                  router.get(
                    ROUTES.scheduling,
                    { sort: filters.sort },
                    { preserveScroll: true, replace: true },
                  )
                }}
              >
                Clear filters
              </Button>
            ) : (
              <Button onClick={() => router.get(ROUTES.schedulingCalendar)}>
                Go to Calendar
              </Button>
            )
          }
        />
      ) : (
        <>
          <div className="space-y-5">
            {jobs.data.map((job, index) => (
              <UnassignedJobRow
                key={job.id}
                job={job}
                index={index}
                onSchedule={setScheduling}
              />
            ))}
          </div>

          <Pagination
            withLabels
            tone="light"
            className="mt-8"
            page={jobs.meta.current_page}
            pageCount={jobs.meta.last_page}
            summary={`Showing ${jobs.data.length} of ${jobs.meta.total} clients`}
            onPageChange={(page) => apply({ page })}
          />
        </>
      )}

      <AssignCrewModal
        job={scheduling}
        onClose={() => setScheduling(null)}
        crews={crews}
        members={members}
        defaultDate={today}
      />
    </PageTransition>
  )
}

SchedulingUnassigned.layout = appLayout
