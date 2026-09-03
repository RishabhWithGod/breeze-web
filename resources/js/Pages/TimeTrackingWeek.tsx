import { Head, router } from '@inertiajs/react'
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react'
import {
  Button,
  ButtonLink,
  Card,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { WeekGrid } from '@/components/timeTracking'
import { ROUTES } from '@/constants'
import type { TimesheetWeek } from '@/types'
import { formatDate } from '@/utils'

export interface TimeTrackingWeekProps {
  week: TimesheetWeek
  prevWeekDate: string
  nextWeekDate: string
  thisWeekDate: string
}

/** The weekly timesheet — regular, overtime, billable and total, one glance per day. */
export default function TimeTrackingWeek({
  week,
  prevWeekDate,
  nextWeekDate,
  thisWeekDate,
}: TimeTrackingWeekProps) {
  const goTo = (date: string) => {
    router.get(ROUTES.timeTrackingWeek, { date }, { preserveScroll: true })
  }

  return (
    <PageTransition>
      <Head title="Weekly Timesheet" />

      <PageHeader
        title="Weekly Timesheet"
        subtitle={`${formatDate(week.weekStart)} – ${formatDate(week.weekEnd)}`}
        breadcrumbs={[{ label: 'Time Tracking', href: ROUTES.timeTracking }, { label: 'Week' }]}
        actions={
          <>
            <Button variant="secondary" leftIcon={ChevronLeft} onClick={() => goTo(prevWeekDate)}>
              Previous
            </Button>
            <Button variant="secondary" onClick={() => goTo(thisWeekDate)}>
              This Week
            </Button>
            <Button variant="secondary" rightIcon={ChevronRight} onClick={() => goTo(nextWeekDate)}>
              Next
            </Button>
                      <ButtonLink href={ROUTES.timeTracking} variant="secondary" leftIcon={ArrowLeft}>
              Back
            </ButtonLink>
          </>
        }
      />

      <Card padding="lg">
        <WeekGrid week={week} />
      </Card>
    </PageTransition>
  )
}

TimeTrackingWeek.layout = appLayout
