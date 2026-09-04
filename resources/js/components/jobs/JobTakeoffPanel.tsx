import { Sparkles } from 'lucide-react'
import { Alert, ButtonLink, Card, SectionHeading, Table } from '@/components/common'
import type { JobBoqLine, JobTakeoff, TableColumn } from '@/types'
import { formatCurrency } from '@/utils'

export interface JobTakeoffPanelProps {
  takeoff: JobTakeoff
}

/**
 * The bill of quantities the job was built from.
 *
 * The lines are the ones copied at creation, so a later re-review of the drawing
 * cannot silently change what the crew is building to.
 */
export function JobTakeoffPanel({ takeoff }: JobTakeoffPanelProps) {
  const boqLines = takeoff.boqLines ?? []

  const columns: readonly TableColumn<JobBoqLine>[] = [
    {
      key: 'symbol',
      header: 'Item',
      render: (row) => <span className="font-medium text-white">{row.symbol}</span>,
    },
    {
      key: 'count',
      header: 'Qty',
      align: 'right',
      width: 'w-24',
      render: (row) => `${row.count} ${row.unit}`,
    },
    {
      key: 'labor',
      header: 'Hours',
      align: 'right',
      width: 'w-24',
      render: (row) => row.labor_hours,
    },
    {
      key: 'cost',
      header: 'Cost',
      align: 'right',
      width: 'w-28',
      render: (row) => (
        <span className="font-semibold text-white">{formatCurrency(row.extended_cost)}</span>
      ),
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      {/*
        The one distinction that matters on this panel: whose numbers these are.
        Until the review is signed off they are the engine's, and they will move.
      */}
      {takeoff.reviewed === false && (
        <Alert tone="warning" title="This job's quantities haven't been reviewed yet">
          Please review the drawing to confirm these counts before finalizing this job.
          <ButtonLink href={takeoff.reviewUrl} size="sm" className="mt-3" leftIcon={Sparkles}>
            Review the symbols
          </ButtonLink>
        </Alert>
      )}

      {boqLines.length > 0 && (
        <Card accent="warning" padding="lg">
          <SectionHeading
            as="h3"
            title="Bill of quantities"
            subtitle={
              takeoff.reviewed === false
                ? 'From the AI response — rewritten when the review is signed off'
                : 'Copied from the reviewed takeoff'
            }
          />
          <Table
            columns={columns}
            rows={boqLines}
            getRowId={(row) => row.symbol}
            variant="lined"
            dense
            caption="Bill of quantities carried onto this job"
          />
        </Card>
      )}
    </div>
  )
}
