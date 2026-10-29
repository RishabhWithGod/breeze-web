import { useMemo, useRef, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  Box,
  Boxes,
  Download,
  ListChecks,
  RotateCcw,
  Route as RouteIcon,
  Ruler,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import { Alert, Button, ButtonLink, SelectField } from '@/components/common'
import { appLayout, PageHeader, PageTransition, StepWizard } from '@/components/layout'
import {
  DEFAULT_ASSUMPTIONS,
  DetailsPanel,
  LAYERS,
  LayersPanel,
  SpatialViewer,
  SymbolsPanel,
  buildSpatialModel,
  defaultScope,
  placeableByPage,
  routesToCsv,
  sampleSymbols,
  symbolGroups,
  visibleStats,
  type Assumptions,
  type LayerKey,
  type SceneSymbols,
  type Scope,
  type SymbolLook,
  type SpatialViewerHandle,
  type ViewPreset,
} from '@/components/spatial'
import { ROUTES, routeTo } from '@/constants'
import type { OverlaySymbol, PageDimensions, ReviewTally } from '@/types'
import { cn } from '@/utils'
import { categoryKey, resolveSymbolColors, symbolLabel } from '@/utils/symbolColor'

export interface ThreeDViewProps {
  /** The takeoff being looked at; null for the sample room, which has no takeoff behind it. */
  result: {
    readonly id: number
    readonly projectName: string | null
    readonly drawingName: string | null
    readonly pageCount: number
    readonly isFinalised: boolean
    readonly reviewUrl: string
    readonly projectId: number | null
    readonly estimateId: number | null
  } | null
  /** The review's own rows, unfiltered — one per category, each with its occurrences. */
  overlaySymbols: readonly OverlaySymbol[]
  /** Keyed by page number. A page the engine never measured is simply absent. */
  pageDimensions: Record<number, PageDimensions>
  /** How much of the review is decided; null for the sample. */
  tally: ReviewTally | null
  /** Each review row's picture, by id. */
  symbolImages?: Record<number, string>
}

const ALL_LAYERS: ReadonlySet<LayerKey> = new Set(LAYERS.map((layer) => layer.key))

const VIEWS: readonly { readonly key: ViewPreset; readonly label: string }[] = [
  { key: 'iso', label: 'Isometric' },
  { key: 'top', label: 'Top' },
  { key: 'front', label: 'Front' },
]

/**
 * 3D Review — the takeoff stood up in a room.
 *
 * The symbols the drawing detected are placed where the drawing put them; everything else — how high, how
 * big the room is in feet, and every wire route — is assumed, and the screen says so. It is a way to
 * inspect, never a way to change a quantity: nothing here writes to the takeoff.
 */
export default function ThreeDView({
  result,
  overlaySymbols,
  pageDimensions,
  tally,
  symbolImages: pictures,
}: ThreeDViewProps) {
  const sample = result === null
  const room = useMemo(() => (sample ? sampleSymbols() : null), [sample])
  const symbols = room ? room.symbols : overlaySymbols
  const dimensions: Record<number, PageDimensions> = useMemo(
    () => (room ? { 1: room.dimensions } : pageDimensions),
    [room, pageDimensions],
  )
  // Where the drawing's symbols are: a takeoff of many sheets has them on a few, rarely on the first.
  const counts = useMemo(() => placeableByPage(symbols), [symbols])
  const sheets = useMemo(() => [...counts.keys()].sort((a, b) => a - b), [counts])
  const first = sheets[0] !== undefined ? dimensions[sheets[0]] : undefined
  const overlay =
    sheets.length > 1 &&
    first !== undefined &&
    sheets.every((value) => dimensions[value]?.width === first.width && dimensions[value]?.height === first.height)

  const [scope, setScope] = useState<Scope>(() => defaultScope(symbols, dimensions))
  const [assumptions, setAssumptions] = useState<Assumptions>(DEFAULT_ASSUMPTIONS)
  const [layers, setLayers] = useState<ReadonlySet<LayerKey>>(ALL_LAYERS)
  const [hiddenCircuits, setHiddenCircuits] = useState<ReadonlySet<string>>(new Set())
  const [context, setContext] = useState(true)
  const [labels, setLabels] = useState(true)
  const [hiddenSymbols, setHiddenSymbols] = useState<ReadonlySet<number>>(new Set())
  const [symbolImages, setSymbolImages] = useState(true)
  const [symbolNames, setSymbolNames] = useState(true)
  const [selected, setSelected] = useState<string | null>(null)
  const [view, setView] = useState<ViewPreset>('iso')
  const [finalising, setFinalising] = useState(false)
  const viewer = useRef<SpatialViewerHandle>(null)

  const built = useMemo(
    () => buildSpatialModel(symbols, scope, dimensions, assumptions),
    [symbols, scope, dimensions, assumptions],
  )
  const model = built.ok ? built.model : null

  const groups = useMemo(() => (model ? symbolGroups(model) : []), [model])
  // The same colour the 2D review gives each symbol, and its picture from the drawing.
  const colors = useMemo(() => resolveSymbolColors(symbols.map((symbol) => symbol.name)), [symbols])
  const looks = useMemo(() => {
    const map = new Map<number, SymbolLook>()
    for (const symbol of symbols) {
      map.set(symbol.id, {
        color: colors.get(categoryKey(symbol.name))?.solid ?? '#94a3b8',
        image: pictures?.[symbol.id] ?? null,
        label: symbolLabel(symbol.name),
      })
    }

    return map
  }, [symbols, colors, pictures])
  const sceneSymbols: SceneSymbols = useMemo(() => {
    const map = new Map<number, { name: string; color: string; image: string | null }>()
    for (const [id, look] of looks) map.set(id, { name: look.label, color: look.color, image: look.image })

    return map
  }, [looks])

  const stats = useMemo(
    () => (model ? visibleStats(model, layers, hiddenCircuits, hiddenSymbols) : null),
    [model, layers, hiddenCircuits, hiddenSymbols],
  )
  const sceneState = useMemo(
    () => ({ layers, hiddenCircuits, hiddenSymbols, symbolImages, symbolNames, context, labels, selected }),
    [layers, hiddenCircuits, hiddenSymbols, symbolImages, symbolNames, context, labels, selected],
  )
  const drawnIds = useMemo(() => groups.filter((group) => group.notDrawn === null).map((group) => group.id), [groups])
  const toggleSymbol = (id: number) =>
    setHiddenSymbols((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)

      return next
    })
  const onlySymbol = (id: number) =>
    setHiddenSymbols((current) => {
      const alone = drawnIds.every((other) => (other === id ? !current.has(other) : current.has(other)))

      return alone ? new Set() : new Set(drawnIds.filter((other) => other !== id))
    })
  const selectedSymbol = model?.components.find((component) => component.id === selected)?.symbolId ?? null

  const choose = (id: string | null) => {
    setSelected(id)
    if (id) viewer.current?.focus(id)
  }

  const toggleLayer = (layer: LayerKey) =>
    setLayers((current) => {
      const next = new Set(current)
      if (next.has(layer)) next.delete(layer)
      else next.add(layer)

      return next
    })
  // Isolate: only this layer — and again to put every layer back.
  const isolate = (layer: LayerKey) =>
    setLayers((current) => (current.size === 1 && current.has(layer) ? ALL_LAYERS : new Set([layer])))
  const showAll = () => {
    setLayers(ALL_LAYERS)
    setHiddenCircuits(new Set())
    setHiddenSymbols(new Set())
  }
  const toggleCircuit = (id: string) =>
    setHiddenCircuits((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)

      return next
    })

  const inspectFirst = () => {
    const first = model?.routes.find((route) => !hiddenCircuits.has(route.circuitId))
    if (first) choose(`r:${first.id}`)
  }

  const exportRoutes = () => {
    if (!model) return
    const blob = new Blob([routesToCsv(model, hiddenCircuits, hiddenSymbols)], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `3d-routes-${(result?.projectName ?? 'sample').toLowerCase().replace(/[^a-z0-9]+/g, '-')}.csv`
    link.click()
    URL.revokeObjectURL(url)
  }

  // Same rule as the review screen's own footer: signing off needs at least one approved symbol.
  const approved = tally?.approved ?? 0
  const pending = tally?.pending ?? 0
  const signedOff = result?.isFinalised === true
  const finalise = () => {
    if (!result || finalising) return
    setFinalising(true)
    router.post(routeTo.reviewFinalise(result.id), {}, { onFinish: () => setFinalising(false) })
  }
  const continueHref =
    signedOff && result?.estimateId
      ? routeTo.estimateInFlow(result.estimateId)
      : signedOff && result
        ? routeTo.finalSymbols(result.id)
        : null

  const title = sample ? 'Sample 3D' : '3D Review'
  const subject = sample ? 'Sample room' : (result?.projectName ?? 'Takeoff')

  return (
    <PageTransition>
      <Head title={sample ? 'Sample 3D' : `3D Review — ${subject}`} />

      {result ? (
        <StepWizard
          current="review"
          currentLabel="Optional 3D inspection"
          hrefs={{
            upload: ROUTES.upload,
            ...(result.projectId ? { analysis: routeTo.processing(result.projectId) } : {}),
          }}
        />
      ) : null}

      <PageHeader
        title={title}
        subtitle={`${subject} · Optional spatial review. Inspect assumed routes without changing takeoff quantities.`}
        breadcrumbs={[
          { label: 'AI Takeoff', href: ROUTES.aiTakeoff },
          ...(result ? [{ label: subject, href: result.reviewUrl }] : []),
          { label: title },
        ]}
        actions={
          <>
            <ButtonLink href={result ? result.reviewUrl : ROUTES.upload} variant="secondary" leftIcon={ArrowLeft}>
              {result ? 'Back to 2D Review' : 'Back to Upload'}
            </ButtonLink>
            <Button
              variant="secondary"
              leftIcon={Download}
              disabled={!model || model.routes.length === 0}
              onClick={exportRoutes}
            >
              Export Visible Routes
            </Button>
            {result &&
              (continueHref ? (
                <ButtonLink href={continueHref} rightIcon={ArrowRight}>
                  Continue to Estimate
                </ButtonLink>
              ) : (
                <Button
                  rightIcon={ArrowRight}
                  isLoading={finalising}
                  disabled={approved === 0}
                  title={approved === 0 ? 'Approve at least one symbol to continue.' : undefined}
                  onClick={finalise}
                >
                  Continue to Estimate
                </Button>
              ))}
          </>
        }
      />

      {result && pending > 0 && !signedOff && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-status-warning/50 bg-status-warning/10 px-4 py-3">
          <div>
            <p className="text-sm font-semibold text-status-warning">Estimator review required</p>
            <p className="text-sm text-white/85">
              Review {pending} remaining {pending === 1 ? 'item' : 'items'}: approve or reject each so the estimate
              counts what you decided.
            </p>
          </div>
          <ButtonLink href={result.reviewUrl} variant="secondary" size="sm" leftIcon={ListChecks}>
            Review Remaining Items
          </ButtonLink>
        </div>
      )}

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-brand/40 bg-brand/8 px-4 py-3">
        <div className="flex items-start gap-3">
          <Box size={20} aria-hidden className="mt-0.5 shrink-0 text-brand" />
          <div>
            <p className="text-sm font-semibold text-white">3D routing · assumed geometry and elevations</p>
            <p className="text-xs leading-snug text-white/80">
              {sample
                ? 'This scene demonstrates where wires could run. It is not extracted from any drawing, is not construction-approved, and does not change any takeoff quantities.'
                : `Symbols are placed where ${result?.drawingName ?? 'the drawing'} detected them. The floor size, heights and every wire route are assumed, are not construction-approved, and do not change your reviewed takeoff quantities.`}
            </p>
          </div>
        </div>
        <span className="rounded-full border border-status-warning/60 bg-status-warning/12 px-3 py-1 text-2xs font-bold tracking-wide text-status-warning">
          NOT FOR CONSTRUCTION
        </span>
      </div>

      {(sheets.length > 1 || (sheets.length === 1 && !sample)) && (
        <div className="mb-4 flex flex-wrap items-end gap-x-4 gap-y-2">
          <SelectField
            id="spatial-sheet"
            label="Drawing sheet"
            className="w-72"
            options={[
              ...(overlay ? [{ value: 'all', label: `All sheets overlaid (${sheets.join(', ')})` }] : []),
              ...sheets.map((value) => ({
                value: String(value),
                label: `Page ${value} · ${counts.get(value) ?? 0} symbols`,
              })),
            ]}
            value={String(scope)}
            onChange={(event) => {
              setScope(event.target.value === 'all' ? 'all' : Number(event.target.value))
              setSelected(null)
            }}
          />
          <p className="max-w-xl pb-2 text-xs text-white/65">
            {scope === 'all'
              ? `Pages ${sheets.join(', ')} are the same size, so they are laid over one another as one floor — power, lighting and the rest in one room.`
              : overlay
                ? 'One sheet on its own. Choose “All sheets overlaid” to see them together.'
                : 'Symbols are on these sheets of the drawing; the 3D view shows the one chosen.'}
          </p>
        </div>
      )}

      {!built.ok && (
        <Alert
          tone="warning"
          className="mb-4"
          title={built.reason === 'no-page-size' ? 'This sheet was not measured' : 'Nothing to place on this sheet'}
        >
          {built.reason === 'no-page-size'
            ? 'The engine never reported this sheet’s size, so symbols cannot be placed on it without guessing. Nothing is drawn rather than something wrong.'
            : sheets.length > 0
              ? `No symbols with a position are on this sheet. The drawing’s symbols are on page ${sheets.join(', ')} — choose one above.`
              : 'This takeoff has no approved or unreviewed symbols with a position to place. Conduit, wire and breaker lines are counted in the takeoff but have no point to stand at.'}
        </Alert>
      )}

      {model && stats && (
        <>
          <p className="mb-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-white/80">
            <span className="inline-flex items-center gap-1.5">
              <RouteIcon size={14} aria-hidden className="text-brand" />
              {stats.circuitsShown} / {stats.circuitsTotal} circuits shown
            </span>
            <span className="inline-flex items-center gap-1.5">
              <Boxes size={14} aria-hidden className="text-brand" />
              {stats.components} components visible
            </span>
            <span
              className="inline-flex items-center gap-1.5"
              title="Assumed routes: a sample of where wire could run, not a measured length."
            >
              <Ruler size={14} aria-hidden className="text-brand" />
              {stats.pathLengthFt.toLocaleString('en-US', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1,
              })}{' '}
              ft {sample ? 'sample ' : ''}path length*
            </span>
            {result && (
              <span className="inline-flex items-center gap-1.5">
                <ListChecks size={14} aria-hidden className="text-brand" />
                {approved} approved takeoff items
              </span>
            )}
          </p>

          <div className="grid items-start gap-4 lg:grid-cols-[15.5rem_minmax(0,1fr)_17.5rem]">
            <LayersPanel
              layers={layers}
              onToggle={toggleLayer}
              onIsolate={isolate}
              onShowAll={showAll}
              context={context}
              onContext={setContext}
              labels={labels}
              onLabels={setLabels}
              symbolImages={symbolImages}
              onSymbolImages={setSymbolImages}
              symbolNames={symbolNames}
              onSymbolNames={setSymbolNames}
              assumptions={assumptions}
              onAssumptions={setAssumptions}
              lines={model.assumptions}
            />

            <section
              aria-label="3D model"
              className="min-w-0 overflow-hidden rounded-card border border-hairline glass"
            >
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-hairline px-3 py-2">
                <div
                  role="group"
                  aria-label="View"
                  className="inline-flex overflow-hidden rounded-panel border border-hairline-strong"
                >
                  {VIEWS.map((option) => (
                    <button
                      key={option.key}
                      type="button"
                      aria-pressed={view === option.key}
                      onClick={() => {
                        setView(option.key)
                        viewer.current?.setView(option.key)
                      }}
                      className={cn(
                        'px-3 py-1.5 text-xs font-semibold transition-colors',
                        view === option.key ? 'bg-brand text-brand-ink' : 'bg-white/6 text-white/85 hover:bg-white/12',
                      )}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
                <div className="flex items-center gap-1.5">
                  <ToolButton label="Zoom in" onClick={() => viewer.current?.zoom(0.8)}>
                    <ZoomIn size={16} aria-hidden />
                  </ToolButton>
                  <ToolButton label="Zoom out" onClick={() => viewer.current?.zoom(1.25)}>
                    <ZoomOut size={16} aria-hidden />
                  </ToolButton>
                  <ToolButton
                    label="Reset view"
                    onClick={() => {
                      setView('iso')
                      viewer.current?.setView('iso')
                    }}
                  >
                    <RotateCcw size={16} aria-hidden />
                  </ToolButton>
                </div>
              </div>

              <div className="relative h-[28rem] bg-[#050b16] md:h-[36rem]">
                <SpatialViewer
                  ref={viewer}
                  model={model}
                  state={sceneState}
                  symbols={sceneSymbols}
                  onSelect={setSelected}
                  className="absolute inset-0"
                />
                <div className="pointer-events-none absolute left-3 top-3 text-2xs font-semibold uppercase tracking-wide text-white/45">
                  {sample ? 'Sample model' : 'Model from the drawing'}
                  <span className="block font-normal normal-case tracking-normal">Feet · Y up</span>
                </div>
                <p className="pointer-events-none absolute bottom-2 left-3 text-2xs font-semibold uppercase tracking-wide text-white/40">
                  {model.widthFt} × {model.depthFt} ft {sample ? 'sample' : 'floor'} · Y = elevation · routes assumed
                </p>
              </div>

              <div className="space-y-2 border-t border-hairline px-3 py-2">
                <p className="text-2xs text-white/65">
                  Drag to rotate · scroll/pinch to zoom · right-drag/two fingers to pan · select any route or component
                </p>
                <ul className="flex flex-wrap gap-x-4 gap-y-1" aria-label="Circuits">
                  {model.circuits.map((circuit) => {
                    const on = !hiddenCircuits.has(circuit.id)

                    return (
                      <li key={circuit.id}>
                        <button
                          type="button"
                          aria-pressed={on}
                          title={on ? `Hide ${circuit.name}` : `Show ${circuit.name}`}
                          onClick={() => toggleCircuit(circuit.id)}
                          className={cn(
                            'inline-flex items-center gap-1.5 text-2xs text-white/80 transition-opacity',
                            !on && 'opacity-40 line-through',
                          )}
                        >
                          <span aria-hidden className="size-2 rounded-full" style={{ background: circuit.color }} />
                          {circuit.label} · {circuit.name}
                        </button>
                      </li>
                    )
                  })}
                </ul>
              </div>
            </section>

            <div className="space-y-4">
              <SymbolsPanel
                groups={groups}
                looks={looks}
                hidden={hiddenSymbols}
                onToggle={toggleSymbol}
                onOnly={onlySymbol}
                onShowAll={() => setHiddenSymbols(new Set())}
                onHideAll={() => setHiddenSymbols(new Set(drawnIds))}
                selectedSymbol={selectedSymbol}
              />
              <DetailsPanel
                model={model}
                selected={selected}
                onSelect={choose}
                onInspectFirst={inspectFirst}
                reviewUrl={result?.reviewUrl ?? null}
              />
            </div>
          </div>

          {(model.skipped.linear > 0 ||
            model.skipped.internal > 0 ||
            model.skipped.rejected > 0 ||
            model.skipped.otherSize > 0) && (
            <p className="mt-3 flex items-start gap-2 text-xs text-white/65">
              <AlertTriangle size={14} aria-hidden className="mt-0.5 shrink-0 text-white/50" />
              <span>
                {scope === 'all' ? 'Across these sheets,' : 'On this sheet,'}
                {model.skipped.linear > 0 &&
                  ` ${model.skipped.linear} conduit or wire ${model.skipped.linear === 1 ? 'line is' : 'lines are'}`}
                {model.skipped.internal > 0 &&
                  `${model.skipped.linear > 0 ? ',' : ''} ${model.skipped.internal} ${model.skipped.internal === 1 ? 'breaker' : 'breakers'}`}
                {model.skipped.linear + model.skipped.internal > 0 &&
                  ' counted in the takeoff but not drawn (a length, or inside a panel)'}
                {model.skipped.rejected > 0 &&
                  `${model.skipped.linear + model.skipped.internal > 0 ? '; ' : ' '}${model.skipped.rejected} rejected ${model.skipped.rejected === 1 ? 'symbol is' : 'symbols are'} left out`}
                {model.skipped.otherSize > 0 &&
                  `${model.skipped.linear + model.skipped.internal + model.skipped.rejected > 0 ? '; ' : ' '}${model.skipped.otherSize} on sheets of a different size ${model.skipped.otherSize === 1 ? 'is' : 'are'} left out so nothing lands in the wrong place`}
                .
              </span>
            </p>
          )}
        </>
      )}
    </PageTransition>
  )
}

ThreeDView.layout = appLayout

function ToolButton({ label, onClick, children }: { label: string; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      onClick={onClick}
      className="grid size-8 place-items-center rounded-panel border border-hairline-strong bg-white/6 text-white/85 transition-colors hover:bg-white/12"
    >
      {children}
    </button>
  )
}
