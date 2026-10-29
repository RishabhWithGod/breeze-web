import type { OverlaySymbol, PageDimensions } from '@/types'

/**
 * The 3D model of a takeoff: the drawing's own symbols stood up in a room, with the routes wire could
 * take between them.
 *
 * Two things are real and two are assumed, and the screen says so every time. Where a symbol sits on the
 * floor comes from the drawing (the engine's page coordinates, as a share of the page). How high it is
 * mounted, how tall the ceiling is, how big the floor is in feet, and the path a wire runs are assumptions
 * — the engine measured a sheet of paper, not a room — so none of it changes a takeoff quantity.
 */

export type LayerKey = 'wires' | 'conduit' | 'lighting' | 'devices' | 'panels'

export interface LayerDef {
  readonly key: LayerKey
  readonly label: string
  readonly color: string
}

export const LAYERS: readonly LayerDef[] = [
  { key: 'wires', label: 'Wire routes', color: '#e2e8f0' },
  { key: 'conduit', label: 'Conduit', color: '#94a3b8' },
  { key: 'lighting', label: 'Lighting', color: '#38bdf8' },
  { key: 'devices', label: 'Power & devices', color: '#f59e0b' },
  { key: 'panels', label: 'Panels', color: '#a78bfa' },
]

export type ComponentKind = 'light' | 'receptacle' | 'switch' | 'device' | 'panel' | 'equipment'

interface KindSpec {
  readonly layer: 'lighting' | 'devices' | 'panels'
  readonly prefix: string
  readonly noun: string
  /** Bottom of the thing, in feet above the finished floor. `null` means flush with the ceiling. */
  readonly elevation: number | null
  /** Width, height, depth in feet. */
  readonly size: readonly [number, number, number]
  readonly color: string
}

const KINDS: Record<ComponentKind, KindSpec> = {
  light: {
    layer: 'lighting',
    prefix: 'L',
    noun: 'Light fixture',
    elevation: null,
    size: [4, 0.3, 2],
    color: '#e8f4ff',
  },
  receptacle: {
    layer: 'devices',
    prefix: 'R',
    noun: 'Receptacle',
    elevation: 1.5,
    size: [1.1, 1.3, 0.7],
    color: '#f59e0b',
  },
  switch: {
    layer: 'devices',
    prefix: 'S',
    noun: 'Switch / control',
    elevation: 4,
    size: [0.9, 1.1, 0.6],
    color: '#f472b6',
  },
  device: { layer: 'devices', prefix: 'D', noun: 'Device', elevation: 4, size: [1.2, 1.2, 0.8], color: '#fbbf24' },
  panel: { layer: 'panels', prefix: 'LP', noun: 'Panel', elevation: 2.5, size: [2.4, 4.5, 1.1], color: '#a78bfa' },
  equipment: { layer: 'panels', prefix: 'EQ', noun: 'Equipment', elevation: 0, size: [4, 3, 3], color: '#34d399' },
}

export const kindSpec = (kind: ComponentKind): KindSpec => KINDS[kind]

/** What a drawing category is, spatially — or why it has no place in a room. */
export type Classified = ComponentKind | 'linear' | 'internal'

/**
 * Decides what a category name means in 3D.
 *
 * Conduit and wire lines are quantities of length, not things at a point; breakers live inside a panel.
 * Neither has a position to stand at, so they are counted aside rather than invented a place.
 */
export function classify(name: string): Classified {
  const n = name.toLowerCase()

  if (/\b(conduit|wire|wiring|cable|cabling|raceway|feeder|thhn|romex|ground wire|bare copper)\b/.test(n))
    return 'linear'
  if (/\bbreaker\b/.test(n) && !/panel/.test(n)) return 'internal'
  if (/panel|switchboard|load ?center|distribution|\bmdp\b|panelboard/.test(n)) return 'panel'
  // A switch stays a switch even when its name says "light" ("3-way light switch").
  if (/switch|dimmer|occupancy|sensor|photocell|timer/.test(n)) return 'switch'
  // A fan/light combination is a fixture; a fan on its own is equipment.
  if (
    /\blight(?:s|ing)?\b|luminaire|troffer|fixture|downlight|lamp|sconce|\bexit\b|emergency|high ?bay|pendant|\bled\b/.test(
      n,
    )
  )
    return 'light'
  if (
    /transformer|disconnect|motor|\brtu\b|\bahu\b|\bvav\b|rooftop|\bunit\b|\bhvac\b|air handler|condenser|\bfan\b|exhaust|pump|compressor|generator|\bats\b|meter|\bvfd\b|starter|heater|equipment|charger/.test(
      n,
    )
  )
    return 'equipment'
  if (/receptacle|outlet|duplex|gfci|\bgfi\b|socket|\busb\b|floor box|power pole/.test(n)) return 'receptacle'

  return 'device'
}

export type ReviewState = 'approved' | 'rejected' | 'pending'

export interface SpatialComponent {
  readonly id: string
  /** The tag on the model: L-1, R-3, EQ-1, LP-1. */
  readonly label: string
  /** The category the drawing detected it as. */
  readonly name: string
  readonly kind: ComponentKind
  readonly layer: 'lighting' | 'devices' | 'panels'
  /** Feet from the left wall. */
  readonly x: number
  /** Feet above the finished floor, to the bottom of it. */
  readonly y: number
  /** Feet from the back wall. */
  readonly z: number
  readonly size: readonly [number, number, number]
  readonly circuitId: string | null
  readonly review: ReviewState
  /** The review row it came from, so it can be opened in the 2D review. */
  readonly symbolId: number | null
  readonly page: number
  /** True for the main panel the circuits run from. */
  readonly isSource: boolean
  /** True when no panel was detected and this one was assumed. */
  readonly assumed: boolean
}

export interface SpatialCircuit {
  readonly id: string
  readonly label: string
  readonly name: string
  readonly color: string
  readonly wire: string
  readonly breaker: string
  readonly componentIds: readonly string[]
}

export interface SpatialRoute {
  readonly id: string
  readonly label: string
  readonly circuitId: string
  readonly fromId: string
  readonly toId: string
  /** The horizontal run, in model feet: [x, y, z] corners. */
  readonly points: readonly (readonly [number, number, number])[]
  /** Run at ceiling height plus the drop to the device. */
  readonly lengthFt: number
  /** Where the run is, in feet above the finished floor. */
  readonly elevation: number
  readonly wire: string
}

export interface NotDrawn {
  readonly symbolId: number
  readonly name: string
  readonly count: number
  readonly why: 'linear' | 'internal'
}

export interface SpatialModel {
  readonly widthFt: number
  readonly depthFt: number
  readonly heightFt: number
  readonly components: readonly SpatialComponent[]
  readonly circuits: readonly SpatialCircuit[]
  readonly routes: readonly SpatialRoute[]
  /** Symbols in the takeoff that have no point to stand at (a length, or inside a panel), with how many. */
  readonly notDrawn: readonly NotDrawn[]
  /** Counted but not placed, with why — shown so nothing silently goes missing. */
  readonly skipped: {
    readonly linear: number
    readonly internal: number
    readonly rejected: number
    readonly otherSize: number
  }
  readonly assumptions: readonly string[]
}

export interface Assumptions {
  /** How wide the drawing's page is, in feet. The depth follows from the page's proportions. */
  readonly widthFt: number
  readonly ceilingFt: number
}

export const DEFAULT_ASSUMPTIONS: Assumptions = { widthFt: 60, ceilingFt: 12 }

const round1 = (value: number): number => Math.round(value * 10) / 10

/** The point-like things on one page, with where they sit as a fraction of it. */
interface Placed {
  readonly key: string
  readonly name: string
  readonly kind: ComponentKind
  readonly fx: number
  readonly fz: number
  readonly review: ReviewState
  readonly symbolId: number
  readonly page: number
}

export type BuildResult =
  | { readonly ok: true; readonly model: SpatialModel }
  | {
      readonly ok: false
      readonly reason: 'no-page-size' | 'nothing-to-place'
      readonly skipped: SpatialModel['skipped']
    }

/**
 * Builds the room for one page of a drawing.
 *
 * A page the engine never measured is refused, not guessed: standing symbols on a made-up page size
 * puts every one of them somewhere plausible and wrong.
 */
export function buildSpatialModel(
  symbols: readonly OverlaySymbol[],
  scope: Scope,
  dimensions: Readonly<Record<number, PageDimensions>>,
  assumptions: Assumptions = DEFAULT_ASSUMPTIONS,
): BuildResult {
  const skipped = { linear: 0, internal: 0, rejected: 0, otherSize: 0 }

  // The sheet whose size the room takes: the one asked for, or — laid over one another — the first with symbols.
  const pagesWithSymbols = [...placeableByPage(symbols).keys()].sort((a, b) => a - b)
  const reference = scope === 'all' ? (pagesWithSymbols[0] ?? 1) : scope
  const size = dimensions[reference]

  if (!size || size.width <= 0 || size.height <= 0) {
    return { ok: false, reason: 'no-page-size', skipped }
  }

  const placed: Placed[] = []
  const notDrawn = new Map<number, NotDrawn>()

  for (const symbol of symbols) {
    const what = classify(symbol.name)

    for (const box of boxesOf(symbol)) {
      if (scope !== 'all' && box.page !== scope) continue
      if (box.status === 'rejected') {
        skipped.rejected += 1
        continue
      }
      if (what === 'linear' || what === 'internal') {
        if (what === 'linear') skipped.linear += 1
        else skipped.internal += 1
        const seen = notDrawn.get(symbol.id)
        notDrawn.set(symbol.id, { symbolId: symbol.id, name: symbol.name, count: (seen?.count ?? 0) + 1, why: what })
        continue
      }
      // Sheets only lay over one another when they are the same size; a different one would land in the wrong place.
      const own = dimensions[box.page]
      if (!own || own.width !== size.width || own.height !== size.height) {
        skipped.otherSize += 1
        continue
      }

      const [x = 0, y = 0, w = 0, h = 0] = box.bbox
      placed.push({
        key: box.key,
        name: symbol.name,
        kind: what,
        fx: Math.min(1, Math.max(0, (x + w / 2) / size.width)),
        fz: Math.min(1, Math.max(0, (y + h / 2) / size.height)),
        review: box.status === 'approved' ? 'approved' : 'pending',
        symbolId: symbol.id,
        page: box.page,
      })
    }
  }

  if (placed.length === 0) return { ok: false, reason: 'nothing-to-place', skipped }

  return { ok: true, model: assemble(placed, size, assumptions, skipped, [...notDrawn.values()]) }
}

/** What to show: one page of the drawing, or every sheet of the same size laid over one another. */
export type Scope = 'all' | number

interface Box {
  readonly key: string
  readonly bbox: readonly number[]
  readonly page: number
  readonly status: string
}

/** Every position a symbol was detected at. */
function boxesOf(symbol: OverlaySymbol): Box[] {
  if (symbol.occurrences && symbol.occurrences.length > 0) {
    return symbol.occurrences.map((occurrence) => ({
      key: `${symbol.id}:${occurrence.key}`,
      bbox: occurrence.bbox,
      page: occurrence.page,
      status: occurrence.status,
    }))
  }

  return symbol.bbox
    ? [{ key: `${symbol.id}:main`, bbox: symbol.bbox, page: symbol.page ?? 1, status: symbol.status }]
    : []
}

/** For each page, how many symbols on it have a place in a room. */
export function placeableByPage(symbols: readonly OverlaySymbol[]): Map<number, number> {
  const counts = new Map<number, number>()

  for (const symbol of symbols) {
    const what = classify(symbol.name)
    if (what === 'linear' || what === 'internal') continue
    for (const box of boxesOf(symbol)) {
      if (box.status === 'rejected') continue
      counts.set(box.page, (counts.get(box.page) ?? 0) + 1)
    }
  }

  return counts
}

/**
 * Where to open: every sheet laid over one another when more than one has symbols and they are all the
 * same size (a power plan, a lighting plan and a fire alarm plan of one floor), otherwise the busiest page.
 */
export function defaultScope(
  symbols: readonly OverlaySymbol[],
  dimensions: Readonly<Record<number, PageDimensions>>,
): Scope {
  const counts = placeableByPage(symbols)
  const pages = [...counts.keys()].sort((a, b) => a - b)
  if (pages.length === 0) return Number(Object.keys(dimensions)[0] ?? 1)

  const first = dimensions[pages[0]!]
  const sameSize = pages.every(
    (page) => dimensions[page]?.width === first?.width && dimensions[page]?.height === first?.height,
  )
  if (pages.length > 1 && first && sameSize) return 'all'

  return pages.reduce((best, page) => ((counts.get(page) ?? 0) > (counts.get(best) ?? 0) ? page : best), pages[0]!)
}

function assemble(
  placed: readonly Placed[],
  dimensions: PageDimensions,
  assumptions: Assumptions,
  skipped: SpatialModel['skipped'],
  notDrawn: readonly NotDrawn[],
): SpatialModel {
  const widthFt = assumptions.widthFt
  const heightFt = assumptions.ceilingFt

  // The room is the building, not the sheet of paper: fitted around where the symbols are, with a margin.
  // A sheet is mostly title block and white space, and standing the symbols in a room that big leaves them
  // huddled in one corner.
  const fit = (values: readonly number[]): { min: number; max: number } => {
    // Ignoring the outermost 1% either side, so a stray detection at the edge of the sheet does not stretch the room.
    const sorted = [...values].sort((x, y) => x - y)
    const trim = sorted.length >= 50 ? Math.floor(sorted.length * 0.01) : 0
    const low = sorted[trim] ?? 0
    const high = sorted[sorted.length - 1 - trim] ?? 1
    if (high - low < 0.05) return { min: 0, max: 1 }
    const pad = (high - low) * 0.06

    return { min: Math.max(0, low - pad), max: Math.min(1, high + pad) }
  }
  const spanX = fit(placed.map((item) => item.fx))
  const spanZ = fit(placed.map((item) => item.fz))
  const realX = (spanX.max - spanX.min) * dimensions.width
  const realZ = (spanZ.max - spanZ.min) * dimensions.height
  const depthFt = round1(widthFt * (realZ / realX))
  // Anything trimmed off stands at the wall rather than outside it.
  const unit = (value: number): number => Math.min(1, Math.max(0, value))
  const across = (item: Placed): number => unit((item.fx - spanX.min) / (spanX.max - spanX.min))
  const down = (item: Placed): number => unit((item.fz - spanZ.min) / (spanZ.max - spanZ.min))

  // Reading order — rows from the back wall, left to right — so tags number the way a person reads the plan.
  const band = depthFt / 12
  const ordered = [...placed].sort((a, b) => {
    const rowA = Math.floor((down(a) * depthFt) / band)
    const rowB = Math.floor((down(b) * depthFt) / band)

    return rowA - rowB || across(a) - across(b)
  })

  const counters: Record<ComponentKind, number> = {
    light: 0,
    receptacle: 0,
    switch: 0,
    device: 0,
    panel: 0,
    equipment: 0,
  }
  const draft = ordered.map((item) => {
    const base = KINDS[item.kind]
    // A recessed can is small; a wall-mounted light hangs on the wall, not the ceiling.
    const spec: KindSpec =
      item.kind === 'light' && /wall|sconce/i.test(item.name)
        ? { ...base, elevation: 6.5, size: [0.9, 1.1, 0.6] }
        : item.kind === 'light' && /recessed|can\b/i.test(item.name)
          ? { ...base, size: [1.2, 0.3, 1.2] }
          : base
    counters[item.kind] += 1
    const y = spec.elevation === null ? heightFt - spec.size[1] : spec.elevation

    return {
      item,
      spec,
      id: `c${item.key}`,
      label: `${spec.prefix}-${counters[item.kind]}`,
      x: round1(across(item) * widthFt),
      y: round1(y),
      z: round1(down(item) * depthFt),
    }
  })

  const toComponent = (entry: (typeof draft)[number], extra: Partial<SpatialComponent> = {}): SpatialComponent => ({
    id: entry.id,
    label: entry.label,
    name: entry.item.name,
    kind: entry.item.kind,
    layer: entry.spec.layer,
    x: entry.x,
    y: entry.y,
    z: entry.z,
    size: entry.spec.size,
    circuitId: null,
    review: entry.item.review,
    symbolId: entry.item.symbolId,
    page: entry.item.page,
    isSource: false,
    assumed: false,
    ...extra,
  })

  // The panel every circuit runs from: an approved one if there is one, else the first read.
  const panels = draft.filter((entry) => entry.item.kind === 'panel')
  const source = panels.find((entry) => entry.item.review === 'approved') ?? panels[0]

  const assumedSource: SpatialComponent | null = source
    ? null
    : {
        id: 'assumed-panel',
        label: 'LP-1',
        name: 'Panel (assumed)',
        kind: 'panel',
        layer: 'panels',
        x: 1.5,
        y: KINDS.panel.elevation ?? 2.5,
        z: round1(depthFt / 2),
        size: KINDS.panel.size,
        circuitId: null,
        review: 'pending',
        symbolId: null,
        page: placed[0]?.page ?? 1,
        isSource: true,
        assumed: true,
      }

  const sourcePoint = source
    ? { id: source.id, x: source.x, z: source.z, top: source.y + source.spec.size[1] }
    : assumedSource
      ? { id: assumedSource.id, x: assumedSource.x, z: assumedSource.z, top: assumedSource.y + assumedSource.size[1] }
      : null

  // Circuits: lighting north/south of the middle of the room, receptacles and devices, switches, equipment.
  const lights = draft.filter((entry) => entry.item.kind === 'light')
  const north = lights.filter((entry) => entry.z <= depthFt / 2)
  const south = lights.filter((entry) => entry.z > depthFt / 2)
  const splitLights = north.length > 0 && south.length > 0

  const groups: {
    id: string
    label: string
    name: string
    color: string
    wire: string
    breaker: string
    members: typeof draft
  }[] = []
  const addGroup = (
    id: string,
    label: string,
    name: string,
    color: string,
    wire: string,
    breaker: string,
    members: typeof draft,
  ) => {
    if (members.length > 0) groups.push({ id, label, name, color, wire, breaker, members })
  }

  if (splitLights) {
    addGroup('L1', 'L1', 'North lighting', '#38bdf8', '12 AWG', '20 A', north)
    addGroup('L2', 'L2', 'South lighting', '#818cf8', '12 AWG', '20 A', south)
  } else {
    addGroup('L1', 'L1', 'Lighting', '#38bdf8', '12 AWG', '20 A', lights)
  }
  addGroup(
    'P1',
    'P1',
    'Receptacles',
    '#f59e0b',
    '12 AWG',
    '20 A',
    draft.filter((e) => e.item.kind === 'receptacle' || e.item.kind === 'device'),
  )
  addGroup(
    'S1',
    'S1',
    'Switches & controls',
    '#f472b6',
    '14 AWG',
    '15 A',
    draft.filter((e) => e.item.kind === 'switch'),
  )
  addGroup(
    'E1',
    'E1',
    'Equipment',
    '#34d399',
    '10 AWG',
    '30 A',
    draft.filter((e) => e.item.kind === 'equipment'),
  )

  const circuitOf = new Map<string, string>()
  for (const group of groups) for (const member of group.members) circuitOf.set(member.id, group.id)

  const components: SpatialComponent[] = draft.map((entry) =>
    toComponent(entry, {
      circuitId: circuitOf.get(entry.id) ?? null,
      isSource: source?.id === entry.id,
    }),
  )
  if (assumedSource) components.push(assumedSource)

  // Routes: from the panel, each circuit visits its devices nearest-first. Runs go along the ceiling, in
  // straight legs at right angles, then drop to the device.
  const routes: SpatialRoute[] = []
  const circuits: SpatialCircuit[] = []

  groups.forEach((group, index) => {
    const runY = round1(Math.max(heightFt * 0.6, heightFt - 1.2 - index * 0.45))
    let here = sourcePoint
      ? { id: sourcePoint.id, x: sourcePoint.x, z: sourcePoint.z, drop: Math.max(0, runY - sourcePoint.top) }
      : null
    const remaining = [...group.members]
    let number = 0

    circuits.push({
      id: group.id,
      label: group.label,
      name: group.name,
      color: group.color,
      wire: group.wire,
      breaker: group.breaker,
      componentIds: group.members.map((member) => member.id),
    })

    while (here && remaining.length > 0) {
      let best = 0
      let bestDistance = Infinity
      remaining.forEach((candidate, i) => {
        const distance = Math.abs(candidate.x - here!.x) + Math.abs(candidate.z - here!.z)
        if (distance < bestDistance) {
          best = i
          bestDistance = distance
        }
      })

      const target = remaining.splice(best, 1)[0]!
      const drop = Math.max(0, runY - (target.y + target.spec.size[1]))
      number += 1

      routes.push({
        id: `${group.id}-${number}`,
        label: `${group.label}-${number}`,
        circuitId: group.id,
        fromId: here.id,
        toId: target.id,
        points: [
          [here.x, runY, here.z],
          [target.x, runY, here.z],
          [target.x, runY, target.z],
        ],
        // Horizontal run, the drop to this device, and — on a circuit's first leg — the rise out of the panel.
        lengthFt: round1(bestDistance + drop + (number === 1 ? here.drop : 0)),
        elevation: runY,
        wire: group.wire,
      })

      here = { id: target.id, x: target.x, z: target.z, drop: 0 }
    }
  })

  const assumptionLines = [
    `${round1(widthFt)} × ${depthFt} ft building footprint, fitted around the symbols on the drawing`,
    `${heightFt} ft nominal ceiling`,
    'Elevations shown above finished floor',
    'Routes are illustrative centerlines',
  ]
  if (assumedSource) assumptionLines.push('No panel was detected, so one is assumed at the left wall')

  return { widthFt, depthFt, heightFt, components, circuits, routes, skipped, notDrawn, assumptions: assumptionLines }
}

/** What the model shows with these layers and circuits on: the figures in the strip above it. */
export interface VisibleStats {
  readonly circuitsShown: number
  readonly circuitsTotal: number
  readonly components: number
  readonly pathLengthFt: number
}

export function visibleStats(
  model: SpatialModel,
  layers: ReadonlySet<LayerKey>,
  hiddenCircuits: ReadonlySet<string>,
  hiddenSymbols: ReadonlySet<number> = new Set(),
): VisibleStats {
  const shown = (component: SpatialComponent): boolean =>
    layers.has(component.layer) &&
    (component.circuitId === null || !hiddenCircuits.has(component.circuitId)) &&
    (component.symbolId === null || !hiddenSymbols.has(component.symbolId))

  const byId = new Map(model.components.map((component) => [component.id, component]))
  const components = model.components.filter(shown).length
  const routes = layers.has('wires')
    ? model.routes.filter((route) => {
        const to = byId.get(route.toId)

        return !hiddenCircuits.has(route.circuitId) && (to === undefined || to.symbolId === null || !hiddenSymbols.has(to.symbolId))
      })
    : []
  const circuitsShown = model.circuits.filter((circuit) => !hiddenCircuits.has(circuit.id)).length

  return {
    circuitsShown,
    circuitsTotal: model.circuits.length,
    components,
    pathLengthFt: round1(routes.reduce((sum, route) => sum + route.lengthFt, 0)),
  }
}

/** One symbol of the takeoff as the list shows it: how many of it stand in the room. */
export interface SymbolGroup {
  readonly id: number
  readonly name: string
  readonly kind: ComponentKind | null
  /** In the room. */
  readonly count: number
  /** Not in the room, and why — a length or something inside a panel. */
  readonly notDrawn: 'linear' | 'internal' | null
}

/** The symbols of the model, most numerous first, with the ones that have no place in a room last. */
export function symbolGroups(model: SpatialModel): SymbolGroup[] {
  const groups = new Map<number, { id: number; name: string; kind: ComponentKind; count: number }>()

  for (const component of model.components) {
    if (component.symbolId === null) continue
    const seen = groups.get(component.symbolId)
    if (seen) seen.count += 1
    else groups.set(component.symbolId, { id: component.symbolId, name: component.name, kind: component.kind, count: 1 })
  }

  const drawn: SymbolGroup[] = [...groups.values()]
    .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name))
    .map((group) => ({ ...group, notDrawn: null }))
  const aside: SymbolGroup[] = model.notDrawn
    .map((item) => ({ id: item.symbolId, name: item.name, kind: null, count: item.count, notDrawn: item.why }))
    .sort((a, b) => b.count - a.count)

  return [...drawn, ...aside]
}

/** The visible routes as a CSV — what "Export Visible Routes" downloads. */
export function routesToCsv(
  model: SpatialModel,
  hiddenCircuits: ReadonlySet<string>,
  hiddenSymbols: ReadonlySet<number> = new Set(),
): string {
  const byId = new Map(model.components.map((component) => [component.id, component]))
  const circuits = new Map(model.circuits.map((circuit) => [circuit.id, circuit]))
  const rows = model.routes.filter((route) => {
    const to = byId.get(route.toId)

    return !hiddenCircuits.has(route.circuitId) && (to?.symbolId == null || !hiddenSymbols.has(to.symbolId))
  })
  const quote = (value: string | number): string => `"${String(value).replace(/"/g, '""')}"`

  const lines = [
    ['Route', 'Circuit', 'From', 'To', 'Wire', 'Elevation (ft)', 'Length (ft)'].map(quote).join(','),
    ...rows.map((route) =>
      [
        route.label,
        circuits.get(route.circuitId)?.name ?? route.circuitId,
        byId.get(route.fromId)?.label ?? route.fromId,
        byId.get(route.toId)?.label ?? route.toId,
        route.wire,
        route.elevation,
        route.lengthFt,
      ]
        .map(quote)
        .join(','),
    ),
    ['', '', '', '', '', 'Total (assumed)', round1(rows.reduce((sum, route) => sum + route.lengthFt, 0))]
      .map(quote)
      .join(','),
  ]

  return lines.join('\n')
}

/**
 * A small room to explore without a drawing: 60 × 40 ft, six fixtures, four receptacles, one piece of
 * equipment and a panel — the "sample 3D" on the upload screen.
 */
export function sampleSymbols(): { symbols: OverlaySymbol[]; dimensions: PageDimensions } {
  const dimensions = { width: 6000, height: 4000 }
  const at = (id: number, name: string, points: readonly (readonly [number, number])[]): OverlaySymbol => ({
    id,
    name,
    page: 1,
    bbox: null,
    status: 'approved',
    finalCount: points.length,
    aiCount: points.length,
    origin: 'symbol',
    occurrences: points.map(([x, y], index) => ({
      key: `s${index}`,
      bbox: [x - 40, y - 40, 80, 80] as const,
      page: 1,
      status: 'approved' as const,
      confidence: 0.9,
    })),
  })

  return {
    dimensions,
    symbols: [
      at(1, 'LED Troffer 2x4', [
        [1500, 800],
        [3000, 800],
        [4500, 800],
        [1500, 3200],
        [3000, 3200],
        [4500, 3200],
      ]),
      at(2, 'Duplex Receptacle', [
        [300, 1500],
        [5700, 1500],
        [300, 2700],
        [5700, 2700],
      ]),
      at(3, 'Panel LP-1', [[120, 2000]]),
      at(4, 'Rooftop Unit', [[5600, 3600]]),
    ],
  }
}
