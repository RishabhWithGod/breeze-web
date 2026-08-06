import { useCallback, useEffect, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import {
  Briefcase,
  Check,
  FileJson,
  FileSpreadsheet,
  FileText,
  Map,
  Receipt,
  Table2,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  EmptyState,
  Pagination,
  SearchBox,
  SectionHeading,
  SelectField,
  Table,
} from '@/components/common'
import {
  CircuitsPanel,
  EngineBoqPanel,
  EquipmentPanel,
  PanelSchedulesPanel,
  WireSizesPanel,
} from '@/components/finals'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ApprovalHistoryPanel, EngineWarnings, PipelineStatus } from '@/components/review'
import { FINAL_SORT_OPTIONS, FINAL_SOURCE_OPTIONS, ROUTES, routeTo } from '@/constants'
import { useDebouncedValue } from '@/hooks'
import type {
  ApprovalHistoryEntry,
  BoqLine,
  BoqMaterial,
  CircuitRow,
  EngineBoqLine,
  EquipmentRow,
  FinalSymbolRow,
  JobForeman,
  Paginated,
  PanelScheduleRow,
  PipelineStage,
  SharedPageProps,
  TableColumn,
  WireSizeRow,
} from '@/types'
import { formatCurrency, formatDate, formatNumber } from '@/utils'

interface FinalResultSummary {
  readonly id: number
  readonly projectId: number
  readonly projectName: string
  readonly client: string | null
  readonly drawingName: string | null
  readonly modelVersion: string | null
  readonly isFinalised: boolean
  readonly finalisedAt: string | null
  readonly pageCount: number
  readonly workJobId: number | null
  readonly workJobName: string | null
  readonly estimateId: number | null
  readonly estimateNumber: string | null
  readonly hasAnnotatedPdf: boolean
  /** Reported by the engine. */
  readonly runId: string | null
  readonly processingTime: number | null
  readonly pipelineStatus: readonly PipelineStage[]
  readonly warnings: readonly string[]
  readonly engineEstimate: {
    readonly subtotal?: number
    readonly tax_rate?: number
    readonly tax?: number
    readonly grand_total?: number
    readonly currency?: string
    readonly line_count?: number
  }
}

export interface FinalSymbolsProps {
  result: FinalResultSummary
  symbols: Paginated<FinalSymbolRow>
  filters: { search: string; source: string; sort: string }
  totals: {
    symbolTypes: number
    items: number
    approved: number
    rejected: number
    modified: number
    aiItems: number
    laborHours: number
    materialCost: number
  }
  boq: { lines: readonly BoqLine[]; materials: readonly BoqMaterial[] }
  /** The engine's own priced bill of quantities. */
  engineBoq: readonly EngineBoqLine[]
  wireSizes: readonly WireSizeRow[]
  panelSchedules: readonly PanelScheduleRow[]
  equipment: readonly EquipmentRow[]
  circuits: readonly CircuitRow[]
  foremen: readonly JobForeman[]
  history: readonly ApprovalHistoryEntry[]
}

/** Tick used for the per-detector columns. */
function SourceTick({ on, label }: { on: boolean; label: string }) {
  return on ? (
    <span className="inline-grid size-5 place-items-center rounded-full bg-status-success/20 text-status-success">
      <Check size={12} strokeWidth={3} aria-hidden />
      <span className="sr-only">{label}</span>
    </span>
  ) : (
    <span className="text-white/25" aria-label={`Not detected by ${label}`}>
      ·
    </span>
  )
}

/**
 * The signed-off takeoff.
 *
 * Every quantity here comes from final_response.json — the reviewed document —
 * and is what the job and estimate are built from. The AI response is kept for
 * audit only and is never read again past this point.
 */
export default function FinalSymbols({
  result,
  symbols,
  filters,
  totals,
  boq,
  engineBoq,
  wireSizes,
  panelSchedules,
  equipment,
  circuits,
  history,
}: FinalSymbolsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [search, setSearch] = useState(filters.search)
  const debouncedSearch = useDebouncedValue(search)
  const rows = symbols.data

  const applyFilters = useCallback(
    (changes: Record<string, string | null>) => {
      const query = new URLSearchParams(window.location.search)

      for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === '' || value === 'all') query.delete(key)
        else query.set(key, value)
      }

      query.delete('page')

      router.get(`${routeTo.finalSymbols(result.id)}?${query.toString()}`, undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
      })
    },
    [result.id],
  )

  useEffect(() => {
    if (debouncedSearch === filters.search) return
    applyFilters({ search: debouncedSearch })
  }, [debouncedSearch, filters.search, applyFilters])

  const columns: readonly TableColumn<FinalSymbolRow>[] = [
    {
      key: 'name',
      header: 'Name',
      render: (row) => (
        <div className="flex min-w-0 items-center gap-2">
          <span className="truncate font-medium text-white">{row.name}</span>
          {row.wasRenamed && (
            <Badge tone="info" size="sm">
              Renamed
            </Badge>
          )}
        </div>
      ),
    },
    {
      key: 'count',
      header: 'Count',
      width: 'w-24',
      render: (row) => <span className="font-semibold text-white">{row.count}</span>,
    },
    {
      key: 'template',
      header: 'Template',
      width: 'w-28',
      render: (row) => <SourceTick on={row.template} label="template matching" />,
    },
    {
      key: 'vector',
      header: 'Vector',
      width: 'w-24',
      render: (row) => <SourceTick on={row.vector} label="vector analysis" />,
    },
    {
      key: 'vision',
      header: 'Vision',
      width: 'w-24',
      render: (row) => <SourceTick on={row.vision} label="vision model" />,
    },
    {
      key: 'ocr',
      header: 'OCR',
      width: 'w-20',
      render: (row) => <SourceTick on={row.ocr} label="OCR" />,
    },
    {
      key: 'sources',
      header: 'Sources',
      render: (row) => (
        <div className="flex flex-wrap gap-1.5">
          {row.sources.map((source) => (
            <Badge key={source} tone="brand" size="sm">
              {source}
            </Badge>
          ))}
        </div>
      ),
    },
    {
      key: 'confidence',
      header: 'Confidence',
      width: 'w-28',
      align: 'right',
      render: (row) => (
        <span className="text-white/85">{Math.round(row.confidence * 100)}%</span>
      ),
    },
  ]

  const stats = [
    { label: 'Symbol types', value: formatNumber(totals.symbolTypes) },
    { label: 'Approved items', value: formatNumber(totals.items) },
    { label: 'AI reported', value: formatNumber(totals.aiItems) },
    { label: 'Rejected', value: formatNumber(totals.rejected) },
    { label: 'Labor hours', value: formatNumber(Math.round(totals.laborHours)) },
    { label: 'Material cost', value: formatCurrency(totals.materialCost) },
  ]

  return (
    <PageTransition>
      <Head title={`Final symbols — ${result.projectName}`} />

      <PageHeader
        title="Final symbol table"
        subtitle={`Reviewed quantities for ${result.projectName}${
          result.finalisedAt ? `, signed off ${formatDate(result.finalisedAt)}` : ''
        }.`}
        breadcrumbs={[
          { label: 'AI Takeoff', href: ROUTES.aiTakeoff },
          { label: result.projectName, href: routeTo.review(result.id) },
          { label: 'Final symbols' },
        ]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ButtonLink
              href={routeTo.review(result.id)}
              variant="ghost"
              size="sm"
              leftIcon={Table2}
            >
              Back to review
            </ButtonLink>
            <ButtonLink
              href={routeTo.finalExport(result.id, 'json')}
              variant="secondary"
              size="sm"
              leftIcon={FileJson}
            >
              JSON
            </ButtonLink>
            <ButtonLink
              href={routeTo.finalExport(result.id, 'csv')}
              variant="secondary"
              size="sm"
              leftIcon={FileText}
            >
              CSV
            </ButtonLink>
            <ButtonLink
              href={routeTo.finalExport(result.id, 'xlsx')}
              variant="secondary"
              size="sm"
              leftIcon={FileSpreadsheet}
            >
              Excel
            </ButtonLink>
            <ButtonLink
              href={routeTo.finalAnnotatedPdf(result.id)}
              variant="secondary"
              size="sm"
              leftIcon={Map}
            >
              Annotated PDF
            </ButtonLink>
          </div>
        }
      />

      {flash.warning && (
        <Alert tone="warning" className="mb-4">
          {flash.warning}
        </Alert>
      )}
      {flash.success && (
        <Alert tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}

      <EngineWarnings warnings={result.warnings} className="mb-4" />

      <div className="mb-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
        {stats.map((stat, index) => (
          <Card key={stat.label} padding="sm" index={index} className="min-w-0">
            <p className="truncate text-2xs tracking-wide text-white/55 uppercase">
              {stat.label}
            </p>
            <p className="mt-1 text-2xl font-bold text-white">{stat.value}</p>
          </Card>
        ))}
      </div>

      {/* Handoff: job first, then the estimate priced from the same document. */}
      <Card padding="md" variant="spotlight" className="mb-4">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <h2 className="text-lg font-semibold text-white">
              {result.isFinalised ? 'Carry this takeoff forward' : 'Finish the review first'}
            </h2>
            <p className="mt-1 text-sm text-white/70">
              {result.isFinalised
                ? 'The job and estimate are built from the reviewed counts in final_response.json, not from the AI response.'
                : 'A job and estimate are built from final_response.json, which only exists once the review is signed off.'}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {!result.isFinalised && (
              <ButtonLink href={routeTo.review(result.id)} leftIcon={Table2}>
                Back to review
              </ButtonLink>
            )}
            {result.workJobId ? (
              <ButtonLink
                href={routeTo.job(result.workJobId)}
                variant="secondary"
                size="sm"
                leftIcon={Briefcase}
              >
                Open job: {result.workJobName}
              </ButtonLink>
            ) : (
              result.isFinalised && (
                <Button
                  size="sm"
                  leftIcon={Briefcase}
                  title="Creates the job from final_response.json and prices it from the engine's bill of quantities"
                  onClick={() => router.post(routeTo.finalCreateJob(result.id))}
                >
                  Create job &amp; estimate
                </Button>
              )
            )}

            {result.estimateId ? (
              <ButtonLink
                href={routeTo.estimate(result.estimateId)}
                variant="secondary"
                size="sm"
                leftIcon={Receipt}
              >
                Open estimate {result.estimateNumber}
              </ButtonLink>
            ) : (
              result.isFinalised && (
                <Button
                  size="sm"
                  leftIcon={Receipt}
                  onClick={() => router.post(routeTo.finalCreateEstimate(result.id))}
                >
                  Create estimate
                </Button>
              )
            )}
          </div>
        </div>
      </Card>

      <Card padding="lg">
        <SectionHeading
          as="h3"
          title="Final symbols"
          subtitle="Search, filter and sort the reviewed quantities"
          actions={
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <SearchBox
                value={search}
                onValueChange={setSearch}
                placeholder="Filter…"
                aria-label="Filter final symbols"
                containerClassName="sm:w-56"
              />
              <SelectField
                id="final-source"
                aria-label="Filter by detection source"
                options={FINAL_SOURCE_OPTIONS}
                value={filters.source}
                onChange={(event) => applyFilters({ source: event.target.value })}
                className="sm:w-44"
              />
              <SelectField
                id="final-sort"
                aria-label="Sort final symbols"
                options={FINAL_SORT_OPTIONS}
                value={filters.sort}
                onChange={(event) => applyFilters({ sort: event.target.value })}
                className="sm:w-56"
              />
            </div>
          }
        />

        <Table
          columns={columns}
          rows={rows}
          getRowId={(row) => row.id}
          variant="lined"
          dense
          caption="Approved symbols and their reviewed counts"
          emptyState={
            <EmptyState
              title="Nothing to show"
              description="No approved symbol matches this filter."
            />
          }
        />

        <Pagination
          className="mt-5"
          page={symbols.meta.current_page}
          pageCount={symbols.meta.last_page}
          onPageChange={(page) => {
            const query = new URLSearchParams(window.location.search)
            query.set('page', String(page))
            router.get(`${routeTo.finalSymbols(result.id)}?${query.toString()}`, undefined, {
              preserveState: true,
            })
          }}
          summary={
            symbols.meta.total > 0
              ? `Showing ${symbols.meta.from}–${symbols.meta.to} of ${symbols.meta.total} symbols`
              : undefined
          }
          withLabels
        />
      </Card>

      {/* Bill of quantities generated from the reviewed counts. */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card padding="lg">
          <SectionHeading
            as="h3"
            title="Bill of quantities"
            subtitle="Devices, their install hours and extended cost"
          />
          <ul className="flex flex-col gap-2">
            {boq.lines.map((line) => (
              <li
                key={line.symbol}
                className="flex min-w-0 items-center justify-between gap-3 rounded-panel bg-white/5 px-4 py-3"
              >
                <div className="min-w-0">
                  <p className="truncate text-md text-white">{line.symbol}</p>
                  <p className="text-2xs text-white/50">
                    {line.count} {line.unit} · {line.labor_hours} hrs
                    {!line.rate_matched && ' · default rate'}
                  </p>
                </div>
                <span className="shrink-0 text-md font-semibold text-white">
                  {formatCurrency(line.extended_cost)}
                </span>
              </li>
            ))}
            {boq.lines.length === 0 && (
              <EmptyState
                title="No bill of quantities yet"
                description="Generate the final JSON to build it."
              />
            )}
          </ul>
        </Card>

        <Card padding="lg">
          <SectionHeading
            as="h3"
            title="Consumables"
            subtitle="Rough-in materials pulled by the approved devices"
          />
          <ul className="flex flex-col gap-2">
            {boq.materials.map((material) => (
              <li
                key={material.description}
                className="flex min-w-0 items-center justify-between gap-3 rounded-panel bg-white/5 px-4 py-3"
              >
                <div className="min-w-0">
                  <p className="truncate text-md text-white">{material.description}</p>
                  <p className="text-2xs text-white/50">
                    {material.quantity} {material.unit} @ {formatCurrency(material.unit_cost)}
                  </p>
                </div>
                <span className="shrink-0 text-md font-semibold text-white">
                  {formatCurrency(material.extended_cost)}
                </span>
              </li>
            ))}
            {boq.materials.length === 0 && (
              <EmptyState
                title="No consumables"
                description="None of the approved symbols pull rough-in materials."
              />
            )}
          </ul>
        </Card>
      </div>

      {/* Priced by the engine — the input the estimate is generated from. */}
      <div className="mt-6">
        <EngineBoqPanel
          lines={engineBoq}
          subtotal={result.engineEstimate.subtotal}
          currency={result.engineEstimate.currency}
        />
      </div>

      {/* Everything else the engine read off the drawing. */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <WireSizesPanel wireSizes={wireSizes} />
        <EquipmentPanel equipment={equipment} />
        <PanelSchedulesPanel schedules={panelSchedules} />
        <CircuitsPanel circuits={circuits} />
      </div>

      <PipelineStatus
        stages={result.pipelineStatus}
        processingTime={result.processingTime}
        className="mt-6"
      />

      <Card padding="lg" className="mt-6">
        <SectionHeading
          as="h3"
          title="Approval history"
          subtitle="Who decided what, and when"
        />
        <ApprovalHistoryPanel entries={history} />
      </Card>
    </PageTransition>
  )
}

FinalSymbols.layout = appLayout
