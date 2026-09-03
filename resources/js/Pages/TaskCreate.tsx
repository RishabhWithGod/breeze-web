import { useState } from 'react'
import { Head, router } from '@inertiajs/react'
import { ArrowLeft, ArrowRight, Briefcase } from 'lucide-react'
import {
  Button,
  ButtonLink,
  Card,
  EmptyState,
  SectionHeading,
  SelectField,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'

export interface TaskCreateProps {
  jobs: readonly {
    readonly id: number
    readonly name: string
    readonly client: string | null
  }[]
}

/**
 * Adding a task starts by naming the job it is for.
 *
 * A task cannot exist on its own, and the work it covers is picked from that
 * job's estimate — so rather than rebuild that form here with a job field bolted
 * on, this hands over to the step that already knows how to lay a job out.
 */
export default function TaskCreate({ jobs }: TaskCreateProps) {
  const [jobId, setJobId] = useState('')

  const jobOptions = [
    { label: 'Select a job', value: '' },
    ...jobs.map((job) => ({
      label: job.client ? `${job.name} — ${job.client}` : job.name,
      value: String(job.id),
    })),
  ]

  return (
    <PageTransition>
      <Head title="Add task" />

      <PageHeader
        title="Add task"
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Tasks', href: ROUTES.tasks },
          { label: 'Add' },
        ]}
        actions={
          <ButtonLink href={ROUTES.tasks} variant="secondary" size="sm" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      <Card padding="lg" className="xl:max-w-2xl">
        {jobs.length === 0 ? (
          <EmptyState
            icon={Briefcase}
            title="No open jobs"
            description="A task needs a job to belong to. Raise one first, then come back."
          />
        ) : (
          <>
            <SectionHeading as="h3" title="Which job?" />

            <SelectField
              id="task-job"
              label="Job*"
              options={jobOptions}
              value={jobId}
              onChange={(event) => setJobId(event.target.value)}
            />

            <div className="mt-8 flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-6">
              <ButtonLink href={ROUTES.tasks} variant="white">
                Cancel
              </ButtonLink>
              <Button
                rightIcon={ArrowRight}
                disabled={jobId === ''}
                {...(jobId === '' ? { title: 'Pick a job to continue' } : {})}
                onClick={() => router.visit(routeTo.jobTaskSetup(Number(jobId)))}
              >
                Continue
              </Button>
            </div>
          </>
        )}
      </Card>
    </PageTransition>
  )
}

TaskCreate.layout = appLayout
