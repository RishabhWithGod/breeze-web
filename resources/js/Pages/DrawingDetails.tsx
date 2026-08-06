import { useState } from 'react'
import { Head } from '@inertiajs/react'
import {
  Briefcase,
  Download,
  FileJson,
  FileText,
  Map,
  Receipt,
  Sparkles,
  Table2,
} from 'lucide-react'
import {
  Badge,
  ButtonLink,
  Card,
  EmptyState,
  FilterTabs,
  SectionHeading,
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
import { ROUTES, routeTo } from '@/constants'
import type {
  ApprovalHistoryEntry,
  CircuitRow,
  EngineBoqLine,
  EquipmentRow,
  PanelScheduleRow,
  PipelineStage,
  WireSizeRow,
} from '@/types'
import { formatCurrency, formatDate, formatFileSize, formatNumber } from '@/utils'

interface DrawingSummary {
  readonly projectId: number
  readonly projectName: string
  readonly client: string | null
  readonly drawingName: string | null
  readonly status: string
  readonly reviewStatus: string
  readonly pageCount: number
  readonly itemsCount: number
  readonly confidence: number | null
  readonly notes: string | null
  readonly uploadedAt: string | null
  readonly completedAt: string | null
  readonly sizeBytes: number | null
  readonly format: string | null
  /** Null when the stored PDF is no longer on disk. */
  readonly fileUrl: string | null
  readonly annotatedUrl: string | null
  readonly previewUrls: readonly string[]
}

interface EngineSummary {
  readonly resultId: number
  readonly runId: string | null
  readonly engineProjectName: string | null
  readonly engineVersion: string | null
  readonly processingTime: number | null
  readonly receivedAt: string | null
  readonly reviewStatus: string
  readonly isFinalised: boolean
  readonly detectionCount: number
  readonly symbolCounts: Readonly<Record<string, number>>
  readonly pipelineStatus: readonly PipelineStage[]
  readonly warnings: readonly string[]
  readonly lifecycleStatistics: Readonly<Record<string, number>> | null
  readonly estimateTotals: {
    readonly subtotal?: number
    readonly tax?: number
    readonly tax_rate?: number
    readonly grand_total?: number
    readonly currency?: string
    readonly line_count?: number
  }
  readonly workJobId: number | null
  readonly workJobName: string | null
  readonly estimateId: number | null
  readonly estimateNumber: string | null
  readonly originalJsonUrl: string
  readonly finalJsonUrl: string | null
}

export interface DrawingDetailsProps {
  drawing: DrawingSummary
  /** Null when the drawing never reached the engine, or the run failed. */
  engine: EngineSummary | null
  boq: readonly EngineBoqLine[]
  wireSizes: readonly WireSizeRow[]
  panelSchedules: readonly PanelScheduleRow[]
  equipment: readonly EquipmentRow[]
  circuits: readonly CircuitRow[]
  history: readonly ApprovalHistoryEntry[]
}

type DocumentView = 'original' | 'annotated'

/**
 * The drawing itself, and every detail the AI engine read off it.
 *
 * Reached from the takeoff history and from an estimate, so a number can be checked
 * against the sheet it came from without hunting for the file. The PDF is embedded
 * from our own storage — the browser's viewer handles pages, zoom and search.
 */
export default function DrawingDetails({
  drawing,
  engine,
  boq,
  wireSizes,
  panelSchedules,
  equipment,
  circuits,
  history,
}: DrawingDetailsProps) {
  const [view, setView] = useState<DocumentView>('original')

  const documentUrl = view === 'annotated' ? drawing.annotatedUrl : drawing.fileUrl
  const symbolCounts = Object.entries(engine?.symbolCounts ?? {})

  const facts: readonly { label: string; value: string }[] = [
    { label: 'Drawing', value: drawing.drawingName ?? '—' },
    { label: 'Pages', value: formatNumber(drawing.pageCount) },
    {
      label: 'File size',
      value: drawing.sizeBytes ? formatFileSize(drawing.sizeBytes) : '—',
    },
    { label: 'Client', value: drawing.client ?? '—' },
    {
      label: 'Uploaded',
      value: drawing.uploadedAt ? formatDate(drawing.uploadedAt) : '—',
    },
    { label: 'Items counted', value: formatNumber(drawing.itemsCount) },
    {
      label: 'Confidence',
      value: drawing.confidence ? `${Math.round(drawing.confidence * 100)}%` : '—',
    },
    {
      label: 'Analysed in',
      value: engine?.processingTime ? `${engine.processingTime.toFixed(1)}s` : '—',
    },
  ]

  return (
    <PageTransition>
      <Head title={`${drawing.drawingName ?? drawing.projectName} — drawing details`} />

      <PageHeader
        title="Drawing details"
        subtitle={`${drawing.projectName}${
          engine?.runId ? ` · engine run ${engine.runId}` : ''
        }`}
        breadcrumbs={[
          { label: 'AI Takeoff', href: ROUTES.aiTakeoff },
          { label: drawing.projectName },
          { label: 'Drawing' },
        ]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {drawing.fileUrl && (
              <ButtonLink
                href={drawing.fileUrl}
                variant="secondary"
                size="sm"
                leftIcon={Download}
              >
                Original PDF
              </ButtonLink>
            )}
            {drawing.annotatedUrl && (
              <ButtonLink
                href={drawing.annotatedUrl}
                variant="secondary"
                size="sm"
                leftIcon={Map}
              >
                Annotated PDF
              </ButtonLink>
            )}
            {engine && (
              <>
                <ButtonLink
                  href={engine.originalJsonUrl}
                  variant="ghost"
                  size="sm"
                  leftIcon={FileJson}
                >
                  Engine JSON
                </ButtonLink>
                {engine.finalJsonUrl && (
                  <ButtonLink
                    href={engine.finalJsonUrl}
                    variant="ghost"
                    size="sm"
                    leftIcon={FileJson}
                  >
                    final_response.json
                  </ButtonLink>
                )}
                <ButtonLink
                  href={
                    engine.isFinalised
                      ? routeTo.finalSymbols(engine.resultId)
                      : routeTo.review(engine.resultId)
                  }
                  size="sm"
                  leftIcon={engine.isFinalised ? Table2 : Sparkles}
                >
                  {engine.isFinalised ? 'Final symbols' : 'Review symbols'}
                </ButtonLink>
              </>
            )}
          </div>
        }
      />

      {engine && <EngineWarnings warnings={engine.warnings} className="mb-4" />}

      {/* Where this drawing is in the workflow, and the one action that moves it on. */}
      {engine && (
        <Card padding="md" variant="spotlight" className="mb-6">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="min-w-0">
              <h2 className="text-lg font-semibold text-white">
                {engine.isFinalised
                  ? engine.workJobId
                    ? 'This drawing has been signed off and built into a job'
                    : 'Reviewed and signed off — ready to become a job'
                  : 'Waiting on review'}
              </h2>
              <p className="mt-1 text-sm text-white/70">
                {engine.isFinalised
                  ? 'The reviewed counts in final_response.json are what the job and estimate were built from.'
                  : `${engine.detectionCount} symbols are waiting for approve, reject, rename or a count change.`}
              </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
              {!engine.isFinalised && (
                <ButtonLink href={routeTo.review(engine.resultId)} leftIcon={Sparkles}>
                  Continue review
                </ButtonLink>
              )}
              {engine.isFinalised && (
                <ButtonLink href={routeTo.finalSymbols(engine.resultId)} leftIcon={Table2}>
                  {engine.workJobId ? 'Final symbol table' : 'Create job & estimate'}
                </ButtonLink>
              )}
              {engine.workJobId && (
                <ButtonLink
                  href={routeTo.job(engine.workJobId)}
                  variant="secondary"
                  leftIcon={Briefcase}
                >
                  {engine.workJobName}
                </ButtonLink>
              )}
              {engine.estimateId && (
                <ButtonLink
                  href={routeTo.estimate(engine.estimateId)}
                  variant="secondary"
                  leftIcon={Receipt}
                >
                  Estimate {engine.estimateNumber}
                </ButtonLink>
              )}
            </div>
          </div>
        </Card>
      )}

      {/* Facts read off the file, before anything was interpreted. */}
      <Card padding="md" className="mb-6">
        <dl className="grid gap-4 sm:grid-cols-3 xl:grid-cols-4">
          {facts.map((fact) => (
            <div key={fact.label} className="min-w-0">
              <dt className="text-2xs tracking-wide text-white/55 uppercase">
                {fact.label}
              </dt>
              <dd className="mt-1 truncate text-md font-semibold text-white" title={fact.value}>
                {fact.value}
              </dd>
            </div>
          ))}
        </dl>

        {drawing.notes && (
          <p className="mt-4 border-t border-hairline pt-4 text-sm text-white/70">
            {drawing.notes}
          </p>
        )}
      </Card>

      <div className="grid gap-6 xl:grid-cols-[1.5fr_1fr]">
        {/* The document itself. */}
        <Card padding="lg" className="min-w-0">
          <SectionHeading
            as="h3"
            title="Document"
            subtitle={
              view === 'annotated'
                ? 'The drawing stamped with every reviewed decision'
                : 'The drawing exactly as uploaded'
            }
            actions={
              drawing.annotatedUrl ? (
                <FilterTabs
                  options={[
                    { value: 'original', label: 'Original' },
                    { value: 'annotated', label: 'Annotated' },
                  ]}
                  value={view}
                  onChange={(next) => setView(next as DocumentView)}
                  solid
                />
              ) : undefined
            }
          />

          {documentUrl ? (
            <div className="overflow-hidden rounded-panel border border-hairline bg-white/5">
              {/*
                The browser's own PDF viewer: pages, zoom, search and print for
                free, and nothing is re-rendered server-side.
              */}
              <object
                data={documentUrl}
                type="application/pdf"
                className="h-[38rem] w-full"
                aria-label={`${drawing.drawingName ?? 'Drawing'} viewer`}
              >
                <div className="p-6 text-center">
                  <p className="text-md text-white/70">
                    This browser cannot display the PDF inline.
                  </p>
                  <ButtonLink href={documentUrl} className="mt-4" leftIcon={Download}>
                    Open the PDF
                  </ButtonLink>
                </div>
              </object>
            </div>
          ) : (
            <EmptyState
              icon={FileText}
              title="The stored drawing is no longer on disk"
              description="Upload the drawing again to re-run the takeoff."
            />
          )}

          {drawing.previewUrls.length > 0 && (
            <div className="mt-4">
              <p className="mb-2 text-sm font-medium text-white/70">
                Rendered pages ({drawing.previewUrls.length})
              </p>
              <ul className="flex gap-3 overflow-x-auto pb-2">
                {drawing.previewUrls.map((url, index) => (
                  <li key={url} className="shrink-0">
                    <a href={url} target="_blank" rel="noreferrer">
                      <img
                        src={url}
                        alt={`Page ${index + 1}`}
                        loading="lazy"
                        className="h-28 rounded-panel border border-hairline bg-white/5 object-cover"
                      />
                      <span className="mt-1 block text-center text-2xs text-white/50">
                        Page {index + 1}
                      </span>
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Card>

        <div className="flex min-w-0 flex-col gap-6">
          {engine ? (
            <>
              <Card padding="lg">
                <SectionHeading
                  as="h3"
                  title="Symbol counts"
                  subtitle={`${engine.detectionCount} symbol types returned by the engine`}
                />
                <div className="flex flex-wrap gap-1.5">
                  {symbolCounts.map(([name, count]) => (
                    <Badge key={name} tone="neutral" size="sm">
                      {name} × {count}
                    </Badge>
                  ))}
                  {symbolCounts.length === 0 && (
                    <span className="text-sm text-white/50">
                      The engine counted nothing on this drawing.
                    </span>
                  )}
                </div>

                <dl className="mt-5 grid gap-4 border-t border-hairline pt-5 sm:grid-cols-2">
                  {[
                    { label: 'Engine version', value: engine.engineVersion ?? '—' },
                    { label: 'Engine run', value: engine.runId ?? 'not resolved' },
                    {
                      label: 'Title block read as',
                      value: engine.engineProjectName ?? '—',
                    },
                    {
                      label: 'Analysed',
                      value: engine.receivedAt ? formatDate(engine.receivedAt) : '—',
                    },
                  ].map((fact) => (
                    <div key={fact.label} className="min-w-0">
                      <dt className="text-2xs tracking-wide text-white/55 uppercase">
                        {fact.label}
                      </dt>
                      <dd className="mt-0.5 truncate text-sm text-white/85" title={fact.value}>
                        {fact.value}
                      </dd>
                    </div>
                  ))}
                </dl>
              </Card>

              <PipelineStatus
                stages={engine.pipelineStatus}
                processingTime={engine.processingTime}
                statistics={engine.lifecycleStatistics}
              />

              {(engine.estimateTotals.grand_total ?? 0) > 0 && (
                <Card padding="lg">
                  <SectionHeading
                    as="h3"
                    title="Engine pricing"
                    subtitle="What the engine priced from this drawing, before review"
                  />
                  <dl className="flex flex-col gap-2">
                    {[
                      ['Subtotal', engine.estimateTotals.subtotal ?? 0],
                      [
                        `Tax (${Math.round((engine.estimateTotals.tax_rate ?? 0) * 100)}%)`,
                        engine.estimateTotals.tax ?? 0,
                      ],
                      ['Grand total', engine.estimateTotals.grand_total ?? 0],
                    ].map(([label, value], index) => (
                      <div
                        key={label as string}
                        className={
                          index === 2
                            ? 'flex items-center justify-between border-t border-hairline pt-2'
                            : 'flex items-center justify-between'
                        }
                      >
                        <dt
                          className={
                            index === 2
                              ? 'text-md font-semibold text-white'
                              : 'text-md text-white/70'
                          }
                        >
                          {label as string}
                        </dt>
                        <dd
                          className={
                            index === 2
                              ? 'text-lg font-bold text-white'
                              : 'text-md text-white/90'
                          }
                        >
                          {formatCurrency(value as number, 2)}
                        </dd>
                      </div>
                    ))}
                  </dl>
                  <p className="mt-3 text-2xs text-white/45">
                    {engine.estimateTotals.line_count ?? 0} priced lines ·{' '}
                    {engine.estimateTotals.currency ?? 'USD'}
                  </p>
                </Card>
              )}

              {(engine.workJobId || engine.estimateId) && (
                <Card padding="lg">
                  <SectionHeading as="h3" title="Carried forward" subtitle="Built from this drawing" />
                  <div className="flex flex-wrap gap-2">
                    {engine.workJobId && (
                      <ButtonLink
                        href={routeTo.job(engine.workJobId)}
                        variant="secondary"
                        size="sm"
                        leftIcon={Briefcase}
                      >
                        {engine.workJobName}
                      </ButtonLink>
                    )}
                    {engine.estimateId && (
                      <ButtonLink
                        href={routeTo.estimate(engine.estimateId)}
                        variant="secondary"
                        size="sm"
                        leftIcon={Receipt}
                      >
                        Estimate {engine.estimateNumber}
                      </ButtonLink>
                    )}
                  </div>
                </Card>
              )}
            </>
          ) : (
            <Card padding="lg">
              <EmptyState
                icon={Sparkles}
                title="This drawing has no analysis"
                description="The AI engine has not returned a result for it — resubmit it from the processing screen."
              />
            </Card>
          )}
        </div>
      </div>

      {/* Everything else the engine read off the sheet. */}
      <div className="mt-6">
        <EngineBoqPanel
          lines={boq}
          subtotal={engine?.estimateTotals.subtotal}
          currency={engine?.estimateTotals.currency}
        />
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <WireSizesPanel wireSizes={wireSizes} />
        <EquipmentPanel equipment={equipment} />
        <PanelSchedulesPanel schedules={panelSchedules} />
        <CircuitsPanel circuits={circuits} />
      </div>

      {history.length > 0 && (
        <Card padding="lg" className="mt-6">
          <SectionHeading
            as="h3"
            title="Takeoff history"
            subtitle="Everything that has happened to this drawing"
          />
          <ApprovalHistoryPanel entries={history} />
        </Card>
      )}
    </PageTransition>
  )
}

DrawingDetails.layout = appLayout
