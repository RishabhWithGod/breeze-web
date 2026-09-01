import { ArrowLeft, ArrowRight, FileText, Layers, Pencil, XCircle } from 'lucide-react'
import { Button, ButtonLink, Card } from '@/components/common'
import type { BoqMaterial } from '@/types'
import { cn, formatCurrency, formatNumber } from '@/utils'

export interface ReviewSummaryPanelProps {
  projectName: string
  client: string | null
  drawingName: string | null
  pageCount: number
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
  materials: readonly BoqMaterial[]
  /**
   * The figure to lead with. Passed in rather than derived here: what counts as the
   * estimated cost is the page's call — the reviewed bill of quantities, or the
   * engine's own grand total when one came back.
   */
  estimatedCost: number
  /** Where "Back to Review" goes. */
  backHref: string
  /** The single primary action. */
  onContinue: () => void
  continueLabel?: string
  isBusy?: boolean
  className?: string
}

/**
 * What the review produced, before anything is priced.
 *
 * The page this replaces opened on a symbol table and a JSON export — accurate, and
 * unreadable to anyone deciding whether the takeoff is right. This answers the three
 * questions instead: what was counted, what changed, and what it is worth.
 *
 * Two actions only, and they are not equals: going back is a link, continuing is the
 * button. The detailed tables are still on the page, below this.
 */
export function ReviewSummaryPanel({
  projectName,
  client,
  drawingName,
  pageCount,
  totals,
  materials,
  estimatedCost,
  backHref,
  onContinue,
  continueLabel = 'Continue to Estimate',
  isBusy = false,
  className,
}: ReviewSummaryPanelProps) {
  const topMaterials = [...materials]
    .sort((a, b) => b.extended_cost - a.extended_cost)
    .slice(0, 6)
  const materialTotal = materials.reduce((sum, row) => sum + row.extended_cost, 0)

  const counts: readonly {
    label: string
    value: string
    hint?: string
    icon: typeof Layers
    tone: string
  }[] = [
    {
      label: 'Detected Symbols',
      value: formatNumber(totals.symbolTypes),
      hint: `${formatNumber(totals.items)} items counted`,
      icon: Layers,
      tone: 'bg-brand/15 text-brand',
    },
    {
      label: 'Modified Symbols',
      value: formatNumber(totals.modified),
      hint: 'Counts or names you changed',
      icon: Pencil,
      tone: 'bg-status-info/15 text-status-info',
    },
    {
      label: 'Rejected Symbols',
      value: formatNumber(totals.rejected),
      hint: 'Left out of the estimate',
      icon: XCircle,
      tone: 'bg-status-danger/20 text-red-300',
    },
  ]

  return (
    <div className={cn('space-y-6', className)}>
      {/* Project and drawing — what this summary is about. */}
      <Card padding="lg">
        <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
          <div className="min-w-0">
            <p className="text-md text-white/80">Client</p>
            <h2 className="mt-1 text-2xl font-bold text-white">{projectName}</h2>
            {client && <p className="mt-1 text-md text-white/85">{client}</p>}

            <div className="mt-5 flex items-center gap-2.5 text-md text-white/90">
              <FileText size={16} aria-hidden className="text-white/65" />
              <span className="min-w-0 truncate">{drawingName ?? 'Drawing'}</span>
              <span className="text-white/30">·</span>
              <span className="whitespace-nowrap text-white/75">
                {pageCount} {pageCount === 1 ? 'page' : 'pages'}
              </span>
            </div>
          </div>

          {/* The number the estimate will be built from. */}
          <div className="shrink-0 rounded-card border border-hairline bg-white/6 px-6 py-5 text-left lg:text-right">
            <p className="text-md text-white/80">Estimated Cost</p>
            <p className="mt-1 text-4xl font-bold text-white tabular-nums">
              {formatCurrency(estimatedCost, 2)}
            </p>
            <p className="mt-1.5 text-sm text-white/75">
              {formatNumber(totals.laborHours)} labour hours
            </p>
          </div>
        </div>
      </Card>

      <div className="grid gap-5 sm:grid-cols-3">
        {counts.map((row, index) => (
          <Card key={row.label} padding="md" index={index}>
            <div className="flex items-start justify-between gap-3">
              <p className="text-md text-white/85">{row.label}</p>
              <span
                aria-hidden
                className={cn('grid size-8 shrink-0 place-items-center rounded-full', row.tone)}
              >
                <row.icon size={16} />
              </span>
            </div>
            <p className="mt-3 text-3xl font-bold text-white tabular-nums">{row.value}</p>
            {row.hint && <p className="mt-1 text-sm text-white/70">{row.hint}</p>}
          </Card>
        ))}
      </div>

      <Card padding="lg">
        <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h3 className="text-xl font-semibold text-white">Material Summary</h3>
            <p className="mt-1 text-md text-white/80">
              {materials.length === 0
                ? 'No materials were priced from this takeoff.'
                : `The ${Math.min(topMaterials.length, materials.length)} largest of ${materials.length} lines.`}
            </p>
          </div>
          <p className="text-right">
            <span className="block text-md text-white/80">Materials total</span>
            <span className="block text-xl font-semibold text-white tabular-nums">
              {formatCurrency(materialTotal, 2)}
            </span>
          </p>
        </div>

        {topMaterials.length > 0 && (
          <ul className="divide-y divide-hairline">
            {topMaterials.map((row) => (
              <li
                key={row.description}
                className="flex items-center justify-between gap-4 py-3"
              >
                <span className="min-w-0">
                  <span className="block truncate font-medium text-white">
                    {row.description}
                  </span>
                  <span className="block text-sm text-white/75">
                    {formatNumber(row.quantity)} {row.unit} ·{' '}
                    {formatCurrency(row.unit_cost, 2)} each
                  </span>
                </span>
                <span className="shrink-0 font-semibold text-white tabular-nums">
                  {formatCurrency(row.extended_cost, 2)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {/* One primary action. Back is a link, so the two never read as equals. */}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <ButtonLink href={backHref} variant="ghost" leftIcon={ArrowLeft}>
          Back to Review
        </ButtonLink>
        <Button size="lg" rightIcon={ArrowRight} onClick={onContinue} isLoading={isBusy}>
          {continueLabel}
        </Button>
      </div>
    </div>
  )
}
