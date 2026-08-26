import {
  FileJson,
  FileSearch,
  FileText,
  Map,
  Sparkles,
  Table2,
} from 'lucide-react'
import { Alert, Badge, ButtonLink, Card, SectionHeading, Table } from '@/components/common'
import type { JobBoqLine, JobTakeoff, TableColumn } from '@/types'
import { formatCurrency, formatNumber } from '@/utils'

export interface JobTakeoffPanelProps {
  takeoff: JobTakeoff
}

/**
 * Everything the job was built from: the drawing, the AI summary, the reviewed
 * counts and the bill of quantities.
 *
 * The numbers are the ones copied at creation, so a later re-review of the drawing
 * cannot silently change what the crew is building to. The document links always
 * point at the current files.
 */
export function JobTakeoffPanel({ takeoff }: JobTakeoffPanelProps) {
  const counts = Object.entries(takeoff.symbolCounts)
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

      <Card padding="lg">
        <SectionHeading
          as="h3"
          title="From AI takeoff"
          subtitle={takeoff.drawingName ?? 'Drawing set'}
          actions={
            <div className="flex flex-wrap gap-2">
              {takeoff.pdfUrl && (
                <ButtonLink
                  href={takeoff.pdfUrl}
                  variant="secondary"
                  size="sm"
                  leftIcon={FileText}
                >
                  Original PDF
                </ButtonLink>
              )}
              <ButtonLink
                href={takeoff.annotatedPdfUrl}
                variant="secondary"
                size="sm"
                leftIcon={Map}
              >
                Annotated PDF
              </ButtonLink>
              {takeoff.pdfDetailsUrl && (
                <ButtonLink
                  href={takeoff.pdfDetailsUrl}
                  variant="ghost"
                  size="sm"
                  leftIcon={FileSearch}
                >
                  PDF details
                </ButtonLink>
              )}
              <ButtonLink
                href={takeoff.finalSymbolsUrl}
                variant="ghost"
                size="sm"
                leftIcon={Table2}
              >
                Final symbols
              </ButtonLink>
              <ButtonLink
                href={takeoff.finalJsonUrl}
                variant="ghost"
                size="sm"
                leftIcon={FileJson}
              >
                Final data (JSON)
              </ButtonLink>
              <ButtonLink
                href={takeoff.reviewUrl}
                variant="ghost"
                size="sm"
                leftIcon={Sparkles}
              >
                Review
              </ButtonLink>
            </div>
          }
        />

        <div className="grid gap-3 sm:grid-cols-4">
          {[
            {
              label: takeoff.reviewed === false ? 'Detected items' : 'Approved items',
              value: formatNumber(takeoff.approvedItems ?? 0),
            },
            { label: 'Symbol types', value: formatNumber(takeoff.symbolTypes ?? counts.length) },
            { label: 'Labor hours', value: formatNumber(Math.round(takeoff.laborHours ?? 0)) },
            {
              label: 'AI estimate',
              value: takeoff.engineEstimate?.grand_total
                ? formatCurrency(takeoff.engineEstimate.grand_total)
                : formatCurrency(takeoff.materialCost ?? 0),
            },
          ].map((stat) => (
            <div key={stat.label} className="min-w-0 rounded-panel bg-white/5 px-4 py-3">
              <p className="truncate text-2xs tracking-wide text-white/80 uppercase">
                {stat.label}
              </p>
              <p className="mt-1 text-xl font-bold text-white">{stat.value}</p>
            </div>
          ))}
        </div>

        <div className="mt-4">
          <p className="mb-2 text-sm font-medium text-white/90">
            {takeoff.reviewed === false ? 'Detected counts (pending review)' : 'Reviewed counts'}
          </p>
          <div className="flex flex-wrap gap-1.5">
            {counts.map(([name, count]) => (
              <Badge key={name} tone="neutral" size="sm">
                {name} × {count}
              </Badge>
            ))}
            {counts.length === 0 && (
              <span className="text-sm text-white/75">No symbol counts recorded.</span>
            )}
          </div>
        </div>

        {(takeoff.wireSizes?.length ?? 0) > 0 && (
          <div className="mt-4">
            <p className="mb-2 text-sm font-medium text-white/90">Wire sizes on the drawing</p>
            <div className="flex flex-wrap gap-1.5">
              {takeoff.wireSizes?.map((wire) => (
                <Badge key={`${wire.size}-${wire.page}`} tone="info" size="sm">
                  {wire.size} × {wire.count} (p{wire.page})
                </Badge>
              ))}
            </div>
          </div>
        )}
      </Card>

      {boqLines.length > 0 && (
        <Card padding="lg">
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
