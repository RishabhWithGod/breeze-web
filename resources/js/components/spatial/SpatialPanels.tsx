import { useState } from 'react'
import { Eye, EyeOff, Layers, MousePointer2, Search, X } from 'lucide-react'
import { Button, StatusChip } from '@/components/common'
import { cn } from '@/utils'
import {
  LAYERS,
  kindSpec,
  type Assumptions,
  type LayerKey,
  type ReviewState,
  type SpatialCircuit,
  type SpatialComponent,
  type SpatialModel,
  type SpatialRoute,
  type SymbolGroup,
} from './model'

const REVIEW_TONE: Record<ReviewState, { label: string; tone: 'success' | 'warning' | 'danger' }> = {
  approved: { label: 'Approved', tone: 'success' },
  pending: { label: 'Unreviewed', tone: 'warning' },
  rejected: { label: 'Rejected', tone: 'danger' },
}

// ---------------------------------------------------------------------------- layers

export function LayersPanel({
  layers,
  onToggle,
  onIsolate,
  onShowAll,
  context,
  onContext,
  labels,
  onLabels,
  symbolImages,
  onSymbolImages,
  symbolNames,
  onSymbolNames,
  assumptions,
  onAssumptions,
  lines,
}: {
  layers: ReadonlySet<LayerKey>
  onToggle: (layer: LayerKey) => void
  onIsolate: (layer: LayerKey) => void
  onShowAll: () => void
  context: boolean
  onContext: (value: boolean) => void
  labels: boolean
  onLabels: (value: boolean) => void
  symbolImages: boolean
  onSymbolImages: (value: boolean) => void
  symbolNames: boolean
  onSymbolNames: (value: boolean) => void
  assumptions: Assumptions
  onAssumptions: (next: Assumptions) => void
  /** The model's own words about what is assumed. */
  lines: readonly string[]
}) {
  const isolated = (layer: LayerKey): boolean => layers.size === 1 && layers.has(layer)

  return (
    <aside aria-label="Layers" className="space-y-4 rounded-card border border-hairline glass p-4">
      <div>
        <div className="mb-2 flex items-center justify-between">
          <h2 className="text-md font-semibold text-white">Layers</h2>
          <button type="button" onClick={onShowAll} className="text-xs font-semibold text-brand hover:underline">
            Show all
          </button>
        </div>
        <ul className="divide-y divide-hairline">
          {LAYERS.map((layer) => (
            <li key={layer.key} className="flex items-center gap-2 py-2">
              <label className="flex flex-1 cursor-pointer items-center gap-2.5 text-sm text-white/90">
                <input
                  type="checkbox"
                  checked={layers.has(layer.key)}
                  onChange={() => onToggle(layer.key)}
                  className="size-4 accent-[var(--color-brand)]"
                />
                <span aria-hidden className="size-2 rounded-full" style={{ background: layer.color }} />
                {layer.label}
              </label>
              <button
                type="button"
                title={isolated(layer.key) ? 'Show every layer again' : `Show only ${layer.label.toLowerCase()}`}
                aria-label={`Isolate ${layer.label}`}
                aria-pressed={isolated(layer.key)}
                onClick={() => onIsolate(layer.key)}
                className={cn(
                  'grid size-7 place-items-center rounded-panel border text-white/70 transition-colors hover:text-white',
                  isolated(layer.key) ? 'border-brand bg-brand/15 text-brand' : 'border-hairline',
                )}
              >
                <Layers size={14} aria-hidden />
              </button>
            </li>
          ))}
        </ul>
      </div>

      <div>
        <h3 className="mb-2 text-xs font-semibold text-white/80">Context &amp; labels</h3>
        <label className="flex cursor-pointer items-center gap-2.5 py-1 text-sm text-white/90">
          <input
            type="checkbox"
            checked={context}
            onChange={(e) => onContext(e.target.checked)}
            className="size-4 accent-[var(--color-brand)]"
          />
          Building context
        </label>
        <label className="flex cursor-pointer items-center gap-2.5 py-1 text-sm text-white/90">
          <input
            type="checkbox"
            checked={labels}
            onChange={(e) => onLabels(e.target.checked)}
            className="size-4 accent-[var(--color-brand)]"
          />
          Component labels
        </label>
        <label className="flex cursor-pointer items-center gap-2.5 py-1 text-sm text-white/90">
          <input
            type="checkbox"
            checked={symbolImages}
            onChange={(e) => onSymbolImages(e.target.checked)}
            className="size-4 accent-[var(--color-brand)]"
          />
          Symbol pictures
        </label>
        <label className="flex cursor-pointer items-center gap-2.5 py-1 text-sm text-white/90">
          <input
            type="checkbox"
            checked={symbolNames}
            onChange={(e) => onSymbolNames(e.target.checked)}
            className="size-4 accent-[var(--color-brand)]"
          />
          Symbol names
        </label>
      </div>

      <div className="rounded-panel border border-hairline bg-white/4 p-3">
        <h3 className="mb-2 text-xs font-semibold text-white">Model assumptions</h3>
        <div className="mb-2 grid grid-cols-2 gap-2">
          <NumberField
            label="Building width (ft)"
            value={assumptions.widthFt}
            min={10}
            max={1000}
            onChange={(widthFt) => onAssumptions({ ...assumptions, widthFt })}
          />
          <NumberField
            label="Ceiling (ft)"
            value={assumptions.ceilingFt}
            min={8}
            max={60}
            onChange={(ceilingFt) => onAssumptions({ ...assumptions, ceilingFt })}
          />
        </div>
        <ul className="space-y-1 text-2xs leading-snug text-white/70">
          {lines.map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
      </div>
    </aside>
  )
}

/** A number typed in, applied when it is a sensible one — nothing happens to the model on every keystroke. */
function NumberField({
  label,
  value,
  min,
  max,
  onChange,
}: {
  label: string
  value: number
  min: number
  max: number
  onChange: (value: number) => void
}) {
  return (
    <label className="block text-2xs text-white/70">
      {label}
      <input
        key={value}
        type="number"
        inputMode="decimal"
        min={min}
        max={max}
        defaultValue={value}
        onBlur={(event) => {
          const next = Number(event.target.value)
          if (Number.isFinite(next) && next >= min && next <= max && next !== value) onChange(next)
          else event.target.value = String(value)
        }}
        onKeyDown={(event) => {
          if (event.key === 'Enter') event.currentTarget.blur()
        }}
        className="mt-0.5 w-full rounded-panel border border-hairline-strong bg-white/8 px-2 py-1 text-sm text-white tabular-nums focus:border-brand focus:outline-none"
      />
    </label>
  )
}

// --------------------------------------------------------------------------- details

export function DetailsPanel({
  model,
  selected,
  onSelect,
  onInspectFirst,
  reviewUrl,
}: {
  model: SpatialModel
  selected: string | null
  onSelect: (id: string | null) => void
  onInspectFirst: () => void
  reviewUrl: string | null
}) {
  const component = selected?.startsWith('c:')
    ? model.components.find((item) => item.id === selected.slice(2))
    : undefined
  const route = selected?.startsWith('r:') ? model.routes.find((item) => item.id === selected.slice(2)) : undefined
  const byId = new Map(model.components.map((item) => [item.id, item]))
  const circuitOf = (id: string | null): SpatialCircuit | undefined =>
    model.circuits.find((circuit) => circuit.id === id)

  return (
    <aside aria-label="Component details" className="rounded-card border border-hairline glass p-4">
      <div className="mb-3 flex items-center justify-between gap-2">
        <h2 className="text-md font-semibold text-white">Component Details</h2>
        {selected && (
          <button
            type="button"
            aria-label="Clear selection"
            onClick={() => onSelect(null)}
            className="text-white/60 hover:text-white"
          >
            <X size={16} aria-hidden />
          </button>
        )}
      </div>

      {component ? (
        <ComponentDetails
          component={component}
          model={model}
          circuit={circuitOf(component.circuitId)}
          byId={byId}
          reviewUrl={reviewUrl}
          onSelect={onSelect}
        />
      ) : route ? (
        <RouteDetails route={route} circuit={circuitOf(route.circuitId)} byId={byId} onSelect={onSelect} />
      ) : (
        <div className="space-y-4">
          <div className="grid place-items-center gap-2 px-2 py-6 text-center">
            <MousePointer2 size={34} aria-hidden className="text-brand" />
            <p className="text-sm font-semibold text-white">Select a route or component</p>
            <p className="text-xs leading-snug text-white/70">
              Click in the scene or choose an item below to see its source, destination, wire size, length, and assumed
              elevation.
            </p>
            <Button size="sm" variant="secondary" disabled={model.routes.length === 0} onClick={onInspectFirst}>
              Inspect First Wire Route
            </Button>
          </div>

          <label className="block text-xs text-white/70">
            Choose an item
            <select
              value=""
              onChange={(event) => onSelect(event.target.value || null)}
              className="mt-1 w-full rounded-panel border border-hairline-strong bg-white/8 px-2 py-1.5 text-sm text-white"
            >
              <option value="">Pick a route or component…</option>
              {model.routes.length > 0 && (
                <optgroup label="Wire routes">
                  {model.routes.slice(0, 200).map((item) => (
                    <option key={item.id} value={`r:${item.id}`}>
                      {item.label} · {byId.get(item.fromId)?.label} → {byId.get(item.toId)?.label}
                    </option>
                  ))}
                </optgroup>
              )}
              <optgroup label="Components">
                {model.components.slice(0, 300).map((item) => (
                  <option key={item.id} value={`c:${item.id}`}>
                    {item.label} · {item.name}
                  </option>
                ))}
              </optgroup>
            </select>
          </label>
        </div>
      )}
    </aside>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-3 border-b border-hairline py-2 text-sm last:border-0">
      <dt className="shrink-0 text-white/65">{label}</dt>
      <dd className="text-right text-white">{children}</dd>
    </div>
  )
}

function ComponentDetails({
  component,
  model,
  circuit,
  byId,
  reviewUrl,
  onSelect,
}: {
  component: SpatialComponent
  model: SpatialModel
  circuit: SpatialCircuit | undefined
  byId: Map<string, SpatialComponent>
  reviewUrl: string | null
  onSelect: (id: string | null) => void
}) {
  const spec = kindSpec(component.kind)
  const feeding = model.routes.find((route) => route.toId === component.id)
  const review = REVIEW_TONE[component.review]
  const circuitsFed = component.isSource ? model.circuits.length : 0

  return (
    <div>
      <p className="text-lg font-bold text-white">{component.label}</p>
      <p className="mb-2 text-sm text-white/75">
        {component.assumed ? 'Panel (assumed — none was detected)' : component.name}
      </p>
      <dl>
        <Row label="Type">{spec.noun}</Row>
        <Row label="Layer">{LAYERS.find((layer) => layer.key === component.layer)?.label}</Row>
        {circuit && (
          <Row label="Circuit">
            <span className="inline-flex items-center gap-1.5">
              <span aria-hidden className="size-2 rounded-full" style={{ background: circuit.color }} />
              {circuit.label} · {circuit.name}
            </span>
          </Row>
        )}
        {component.isSource && (
          <Row label="Feeds">
            {circuitsFed} {circuitsFed === 1 ? 'circuit' : 'circuits'}
          </Row>
        )}
        {feeding && (
          <>
            <Row label="Source">{byId.get(feeding.fromId)?.label}</Row>
            <Row label="Wire size">{feeding.wire}</Row>
            <Row label="Run length">{feeding.lengthFt} ft</Row>
          </>
        )}
        <Row label="Position">
          {component.x} ft from left wall, {component.z} ft from back wall
        </Row>
        <Row label="Elevation">{component.y} ft AFF (assumed)</Row>
        {!component.assumed && (
          <>
            <Row label="Review status">
              <StatusChip pill tone={review.tone} label={review.label} />
            </Row>
            <Row label="Drawing page">{component.page}</Row>
          </>
        )}
      </dl>
      <div className="mt-3 flex flex-wrap gap-2">
        {feeding && (
          <Button size="sm" variant="secondary" onClick={() => onSelect(`r:${feeding.id}`)}>
            Inspect its route
          </Button>
        )}
        {reviewUrl && component.symbolId !== null && (
          <a
            href={reviewUrl}
            className="inline-flex items-center rounded-panel border border-hairline-strong px-3 py-1.5 text-sm font-semibold text-white hover:bg-white/8"
          >
            Open in 2D review
          </a>
        )}
      </div>
    </div>
  )
}

function RouteDetails({
  route,
  circuit,
  byId,
  onSelect,
}: {
  route: SpatialRoute
  circuit: SpatialCircuit | undefined
  byId: Map<string, SpatialComponent>
  onSelect: (id: string | null) => void
}) {
  return (
    <div>
      <p className="text-lg font-bold text-white">Route {route.label}</p>
      <p className="mb-2 text-sm text-white/75">Assumed wire route — not a construction drawing</p>
      <dl>
        {circuit && (
          <Row label="Circuit">
            <span className="inline-flex items-center gap-1.5">
              <span aria-hidden className="size-2 rounded-full" style={{ background: circuit.color }} />
              {circuit.label} · {circuit.name}
            </span>
          </Row>
        )}
        <Row label="Source">{byId.get(route.fromId)?.label}</Row>
        <Row label="Destination">{byId.get(route.toId)?.label}</Row>
        <Row label="Wire size">{route.wire}</Row>
        {circuit && <Row label="Breaker">{circuit.breaker}</Row>}
        <Row label="Length">{route.lengthFt} ft</Row>
        <Row label="Elevation">{route.elevation} ft AFF (assumed)</Row>
      </dl>
      <div className="mt-3 flex flex-wrap gap-2">
        <Button size="sm" variant="secondary" onClick={() => onSelect(`c:${route.toId}`)}>
          Inspect destination
        </Button>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------- symbols

export interface SymbolLook {
  readonly color: string
  readonly image: string | null
  readonly label: string
}

/** The takeoff's symbols as the 2D review lists them — picture, name, count, colour — each with its own show/hide. */
export function SymbolsPanel({
  groups,
  looks,
  hidden,
  onToggle,
  onOnly,
  onShowAll,
  onHideAll,
  selectedSymbol,
}: {
  groups: readonly SymbolGroup[]
  looks: ReadonlyMap<number, SymbolLook>
  hidden: ReadonlySet<number>
  onToggle: (id: number) => void
  /** Show just this symbol — again to put them all back. */
  onOnly: (id: number) => void
  onShowAll: () => void
  onHideAll: () => void
  selectedSymbol: number | null
}) {
  const [query, setQuery] = useState('')
  const needle = query.trim().toLowerCase()
  const rows = needle
    ? groups.filter((group) => (looks.get(group.id)?.label ?? group.name).toLowerCase().includes(needle))
    : groups
  const drawn = groups.filter((group) => group.notDrawn === null)
  const shown = drawn.filter((group) => !hidden.has(group.id)).length
  const only = (id: number): boolean => drawn.length > 1 && shown === 1 && !hidden.has(id)

  return (
    <aside aria-label="Symbols" className="space-y-3 rounded-card border border-hairline glass p-4">
      <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
        <h2 className="text-md font-semibold text-white">
          Symbols{' '}
          <span className="text-xs font-normal text-white/60">
            {shown}/{drawn.length} shown
          </span>
        </h2>
        <div className="flex gap-2 whitespace-nowrap text-xs font-semibold">
          <button type="button" onClick={onShowAll} className="text-brand hover:underline">
            Show all
          </button>
          <button type="button" onClick={onHideAll} className="text-white/70 hover:underline">
            Hide all
          </button>
        </div>
      </div>

      {groups.length > 6 && (
        <label className="relative block">
          <span className="sr-only">Find a symbol</span>
          <Search size={14} aria-hidden className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/50" />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Find a symbol"
            className="w-full rounded-panel border border-hairline-strong bg-white/6 py-1.5 pl-8 pr-2 text-sm text-white placeholder:text-white/40"
          />
        </label>
      )}

      <ul className="max-h-80 divide-y divide-hairline overflow-y-auto pr-1">
        {rows.map((group) => {
          const look = looks.get(group.id)
          const off = group.notDrawn !== null
          const isHidden = hidden.has(group.id)

          return (
            <li
              key={group.id}
              className={cn(
                'flex items-center gap-2 py-1.5',
                off && 'opacity-55',
                selectedSymbol === group.id && 'bg-white/6',
              )}
            >
              <input
                type="checkbox"
                aria-label={`Show ${look?.label ?? group.name}`}
                checked={!off && !isHidden}
                disabled={off}
                onChange={() => onToggle(group.id)}
                className="size-4 shrink-0 accent-[var(--color-brand)]"
              />
              <span
                aria-hidden
                className="grid size-8 shrink-0 place-items-center overflow-hidden rounded-md bg-white"
                style={{ border: `2px solid ${look?.color ?? '#94a3b8'}` }}
              >
                {look?.image ? (
                  <img
                    src={look.image}
                    alt=""
                    className="size-full object-contain"
                    onError={(e) => (e.currentTarget.style.display = 'none')}
                  />
                ) : null}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm text-white/90" title={look?.label ?? group.name}>
                  {look?.label ?? group.name}
                </span>
                {off && (
                  <span className="block text-2xs text-white/55">
                    Not drawn — {group.notDrawn === 'linear' ? 'a length, not a spot' : 'inside a panel'}
                  </span>
                )}
              </span>
              <span className="shrink-0 rounded-full bg-white/10 px-1.5 text-2xs font-semibold text-white/80">
                {group.count}
              </span>
              {!off && (
                <button
                  type="button"
                  title={only(group.id) ? 'Show every symbol again' : 'Show only this symbol'}
                  aria-label={`Only ${look?.label ?? group.name}`}
                  aria-pressed={only(group.id)}
                  onClick={() => onOnly(group.id)}
                  className={cn(
                    'grid size-6 shrink-0 place-items-center rounded-panel border text-white/70 hover:text-white',
                    only(group.id) ? 'border-brand bg-brand/15 text-brand' : 'border-hairline',
                  )}
                >
                  {isHidden ? <EyeOff size={12} aria-hidden /> : <Eye size={12} aria-hidden />}
                </button>
              )}
            </li>
          )
        })}
        {rows.length === 0 && <li className="py-3 text-xs text-white/60">No symbol matches “{query}”.</li>}
      </ul>
    </aside>
  )
}
