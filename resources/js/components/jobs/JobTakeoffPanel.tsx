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
import { TONE_DOT_CLASS, cn, formatCurrency, formatNumber } from '@/utils'

/** The engine's words for a stage, mapped onto the design system's tones. */
const STATUS_TONE: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = {
  ok: 'success',
  parsed: 'success',
  partial: 'warning',
  fallback: 'warning',
  failed: 'danger',
  error: 'danger',
}

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
        <Alert tone="warning" title="These counts are not reviewed yet">
          The job and its estimate were raised from the AI response so the work is
          visible straight away. Signing off the review updates these same records
          with the reviewed counts.
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
                final_response.json
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
              <p className="truncate text-2xs tracking-wide text-white/55 uppercase">
                {stat.label}
              </p>
              <p className="mt-1 text-xl font-bold text-white">{stat.value}</p>
            </div>
          ))}
        </div>

        {/* How the engine fared on this drawing, as it reported it. */}
        {takeoff.pipelineStatus && takeoff.pipelineStatus.length > 0 && (
          <div className="mt-4">
            <p className="mb-2 text-sm font-medium text-white/70">AI summary</p>
            <ul className="flex flex-wrap gap-2">
              {takeoff.pipelineStatus.map((stage) => (
                <li
                  key={stage.stage}
                  className="flex items-center gap-2 rounded-panel bg-white/5 px-3 py-1.5"
                >
                  <span
                    aria-hidden
                    className={cn(
                      'size-2 rounded-full',
                      TONE_DOT_CLASS[STATUS_TONE[stage.status.toLowerCase()] ?? 'neutral'],
                    )}
                  />
                  <span className="text-sm text-white capitalize">{stage.stage}</span>
                  <span className="text-2xs text-white/50 uppercase">{stage.status}</span>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-2xs text-white/45">
              {takeoff.engineVersion ? `Engine ${takeoff.engineVersion}` : 'AI engine'}
              {takeoff.engineRunId ? ` · run ${takeoff.engineRunId}` : ''}
              {takeoff.processingTime ? ` · analysed in ${takeoff.processingTime.toFixed(1)}s` : ''}
            </p>
          </div>
        )}

        {(takeoff.warnings?.length ?? 0) > 0 && (
          <ul className="mt-4 flex flex-col gap-1 rounded-panel bg-status-warning/10 px-4 py-3">
            {takeoff.warnings?.map((warning) => (
              <li key={warning} className="text-2xs text-status-warning">
                {warning}
              </li>
            ))}
          </ul>
        )}

        <div className="mt-4">
          <p className="mb-2 text-sm font-medium text-white/70">
            {takeoff.reviewed === false ? 'Detected counts (pending review)' : 'Reviewed counts'}
          </p>
          <div className="flex flex-wrap gap-1.5">
            {counts.map(([name, count]) => (
              <Badge key={name} tone="neutral" size="sm">
                {name} × {count}
              </Badge>
            ))}
            {counts.length === 0 && (
              <span className="text-sm text-white/50">No symbol counts recorded.</span>
            )}
          </div>
        </div>

        {(takeoff.wireSizes?.length ?? 0) > 0 && (
          <div className="mt-4">
            <p className="mb-2 text-sm font-medium text-white/70">Wire sizes on the drawing</p>
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
