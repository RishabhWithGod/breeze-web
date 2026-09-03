import { useState } from 'react'
import { Head } from '@inertiajs/react'
import { ArrowLeft, Download, FileText, FolderClosed, Map, Sparkles } from 'lucide-react'
import {
  ButtonLink,
  Card,
  EmptyState,
  FilterTabs,
  SectionHeading,
} from '@/components/common'
import { EngineBoqPanel, WireSizesPanel } from '@/components/finals'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { EngineBoqLine, PipelineStage, WireSizeRow } from '@/types'
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
}: DrawingDetailsProps) {
  const [view, setView] = useState<DocumentView>('original')

  const documentUrl = view === 'annotated' ? drawing.annotatedUrl : drawing.fileUrl

  const facts: readonly { label: string; value: string }[] = [
    { label: 'Drawing', value: drawing.drawingName ?? '—' },
    { label: 'Pages', value: formatNumber(drawing.pageCount) },
    {
      label: 'File size',
      value: drawing.sizeBytes ? formatFileSize(drawing.sizeBytes) : '—',
    },
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
        subtitle={drawing.projectName}
        breadcrumbs={[
          { label: 'AI Takeoff', href: ROUTES.aiTakeoff },
          { label: drawing.projectName },
          { label: 'Drawing' },
        ]}
        actions={
          <>
            {/* This takeoff's own paperwork — contracts, submittals, RFIs. */}
            <ButtonLink
              href={routeTo.projectDocuments(drawing.projectId)}
              variant="secondary"
              size="sm"
              leftIcon={FolderClosed}
            >
              Documents
            </ButtonLink>
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
          <ButtonLink href={ROUTES.aiTakeoff} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
          </>
        }
      />

      {/* Facts read off the file, before anything was interpreted. */}
      <Card padding="md" className="mb-6">
        <dl className="grid gap-4 sm:grid-cols-3 xl:grid-cols-4">
          {facts.map((fact) => (
            <div key={fact.label} className="min-w-0">
              <dt className="text-2xs tracking-wide text-white/80 uppercase">
                {fact.label}
              </dt>
              <dd
                className="mt-1 truncate text-md font-semibold text-white"
                title={fact.value}
              >
                {fact.value}
              </dd>
            </div>
          ))}
        </dl>

        {drawing.notes && (
          <p className="mt-4 border-t border-hairline pt-4 text-sm text-white/90">
            {drawing.notes}
          </p>
        )}
      </Card>

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
                <p className="text-md text-white/90">
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
            <p className="mb-2 text-sm font-medium text-white/90">
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
                    <span className="mt-1 block text-center text-2xs text-white/75">
                      Page {index + 1}
                    </span>
                  </a>
                </li>
              ))}
            </ul>
          </div>
        )}
      </Card>

      {!engine && (
        <Card padding="lg" className="mt-6">
          <EmptyState
            icon={Sparkles}
            title="This drawing has no analysis"
            description="No results have come back for it yet — resubmit it from the processing screen."
          />
        </Card>
      )}

      {/* Everything else the engine read off the sheet. */}
      <div className="mt-6">
        <EngineBoqPanel
          lines={boq}
          subtotal={engine?.estimateTotals.subtotal}
          currency={engine?.estimateTotals.currency}
        />
      </div>

      <div className="mt-6">
        <WireSizesPanel wireSizes={wireSizes} />
      </div>

      {/*
        Last, because it is the least trustworthy figure on the page: the
        engine's own pricing, before anyone reviewed a count.
      */}
      {engine && (engine.estimateTotals.grand_total ?? 0) > 0 && (
        <Card padding="lg" className="mt-6">
          <SectionHeading
            as="h3"
            title="Automatic pricing"
            subtitle="What was priced automatically, before your review"
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
                      : 'text-md text-white/90'
                  }
                >
                  {label as string}
                </dt>
                <dd
                  className={
                    index === 2 ? 'text-lg font-bold text-white' : 'text-md text-white/90'
                  }
                >
                  {formatCurrency(value as number, 2)}
                </dd>
              </div>
            ))}
          </dl>
          <p className="mt-3 text-2xs text-white/70">
            {engine.estimateTotals.line_count ?? 0} priced lines ·{' '}
            {engine.estimateTotals.currency ?? 'USD'}
          </p>
        </Card>
      )}
    </PageTransition>
  )
}

DrawingDetails.layout = appLayout
