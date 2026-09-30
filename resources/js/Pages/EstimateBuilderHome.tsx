import { Head, router, useForm } from '@inertiajs/react'
import { Calculator, Plus } from 'lucide-react'
import { Alert, Button, ButtonLink, Card, EmptyState, SelectField, StatusChip, Table } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { TableColumn, Tone } from '@/types'
import { formatCurrency, formatDate } from '@/utils'

interface BuilderEstimate {
  readonly id: number
  readonly number: string
  readonly project: string
  readonly client: string
  readonly status: string
  readonly lines: number
  readonly total: number
  readonly updatedAt: string | null
}

export interface EstimateBuilderHomeProps {
  /** The estimates being built here. */
  estimates: readonly BuilderEstimate[]
  /** Projects an estimate can be started on. */
  projects: readonly { readonly id: number; readonly name: string; readonly client: string | null }[]
}

const STATUS: Record<string, { label: string; tone: Tone }> = {
  draft: { label: 'Draft', tone: 'neutral' },
  sent: { label: 'Awaiting approval', tone: 'warning' },
  approved: { label: 'Approved', tone: 'success' },
  rejected: { label: 'Rejected', tone: 'danger' },
}

/**
 * Estimate Builder — where an estimate is priced.
 *
 * Start one on a project, or pick up one already in progress.
 */
export default function EstimateBuilderHome({ estimates, projects }: EstimateBuilderHomeProps) {
  const { data, setData, post, processing, errors } = useForm({ project_id: '' })

  const columns: TableColumn<BuilderEstimate>[] = [
    {
      key: 'number',
      header: 'Estimate',
      render: (row) => <span className="font-semibold text-white">{row.number}</span>,
    },
    { key: 'project', header: 'Project', render: (row) => <span className="text-white">{row.project}</span> },
    { key: 'client', header: 'Client', render: (row) => <span className="text-white/85">{row.client}</span> },
    {
      key: 'status',
      header: 'Status',
      render: (row) => {
        const status = STATUS[row.status] ?? STATUS['draft']!

        return <StatusChip pill tone={status.tone} label={status.label} />
      },
    },
    {
      key: 'lines',
      header: 'Items',
      align: 'center',
      render: (row) => <span className="tabular-nums text-white">{row.lines}</span>,
    },
    {
      key: 'total',
      header: 'Total',
      align: 'right',
      render: (row) => <span className="font-semibold tabular-nums text-white">{formatCurrency(row.total, 2)}</span>,
    },
    {
      key: 'updated',
      header: 'Updated',
      render: (row) => <span className="text-white/70">{row.updatedAt ? formatDate(row.updatedAt) : '—'}</span>,
    },
    {
      key: 'open',
      header: 'Open',
      align: 'right',
      render: (row) => (
        <ButtonLink href={routeTo.estimateBuilderShow(row.id)} variant="secondary" size="sm">
          {row.status === 'draft' ? 'Continue' : 'View'}
        </ButtonLink>
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title="Estimate Builder" />

      <PageHeader
        title="Estimate Builder"
        subtitle="Turn approved quantities into priced labor and material lines."
        breadcrumbs={[{ label: 'Estimates', href: ROUTES.estimates }, { label: 'Estimate Builder' }]}
      />

      <Card padding="md" className="mb-5">
        <h2 className="mb-1 text-lg font-semibold text-white">Start an estimate</h2>
        <p className="mb-4 text-sm text-white/70">
          Pick the project it is for. You can copy a takeoff in, or price every item by hand.
        </p>
        {projects.length === 0 ? (
          <Alert tone="info">Add a project first — an estimate is always raised on one.</Alert>
        ) : (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              post(ROUTES.estimateBuilder)
            }}
            className="flex flex-wrap items-end gap-3"
          >
            <SelectField
              id="builder-project"
              label="Project"
              className="min-w-72 flex-1 sm:max-w-md"
              options={[
                { value: '', label: 'Select a project' },
                ...projects.map((project) => ({
                  value: String(project.id),
                  label: project.client ? `${project.name} — ${project.client}` : project.name,
                })),
              ]}
              value={data.project_id}
              onChange={(event) => setData('project_id', event.target.value)}
              {...(errors.project_id ? { error: errors.project_id } : {})}
            />
            <Button type="submit" leftIcon={Plus} isLoading={processing} disabled={data.project_id === ''}>
              Start estimate
            </Button>
          </form>
        )}
      </Card>

      <Card padding="md">
        <h2 className="mb-4 text-lg font-semibold text-white">In the builder</h2>
        {estimates.length === 0 ? (
          <EmptyState
            icon={Calculator}
            title="Nothing here yet"
            description="Start an estimate above and it will be listed here."
          />
        ) : (
          <Table
            columns={columns}
            rows={estimates}
            getRowId={(row) => row.id}
            variant="lined"
            caption="Estimates in the builder"
            onRowClick={(row) => router.visit(routeTo.estimateBuilderShow(row.id))}
          />
        )}
      </Card>
    </PageTransition>
  )
}

EstimateBuilderHome.layout = appLayout
