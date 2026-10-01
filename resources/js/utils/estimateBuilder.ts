/** One row of the Estimate Builder's worksheet, as the page holds it. */
export interface BuilderRow {
  /** Stable while editing; the saved id when there is one. */
  readonly key: string
  readonly id: number | null
  description: string
  commodity: string
  unit: string
  materialQty: number
  materialUnitPrice: number
  laborHours: number
  laborRate: number
  markupPct: number
  readonly source: 'manual' | 'takeoff' | 'price-list'
  readonly sourceEstimateItemId: number | null
  readonly priceBookItemId: number | null
}

export interface BuilderTotals {
  readonly material: number
  readonly labor: number
  /** Material and labor before any markup. */
  readonly base: number
  readonly markup: number
  /** With the markup on — what the table's Subtotal column adds up to. */
  readonly subtotal: number
  readonly tax: number
  readonly total: number
}

/** To the cent, without the drift of `1.005 * 100`. */
export const round2 = (value: number): number => Math.round((value + Number.EPSILON) * 100) / 100

export const rowMaterialTotal = (row: BuilderRow): number => round2(row.materialQty * row.materialUnitPrice)

export const rowLaborTotal = (row: BuilderRow): number => round2(row.laborHours * row.laborRate)

/** The markup on a row: taken on each side separately, as the server writes the estimate's two lines. */
export const rowMarkup = (row: BuilderRow): number =>
  round2((rowMaterialTotal(row) * row.markupPct) / 100) + round2((rowLaborTotal(row) * row.markupPct) / 100)

/** Material and labor with the row's markup on top. */
export const rowSubtotal = (row: BuilderRow): number =>
  round2(rowMaterialTotal(row) + rowLaborTotal(row) + rowMarkup(row))

/**
 * The same sum the server does when it saves (`EstimateWorksheet` and
 * `Estimate::recalculateTotals`), so the total on screen is the total kept.
 */
export function builderTotals(rows: readonly BuilderRow[], taxPct: number): BuilderTotals {
  const material = round2(rows.reduce((sum, row) => sum + rowMaterialTotal(row), 0))
  const labor = round2(rows.reduce((sum, row) => sum + rowLaborTotal(row), 0))
  const base = round2(material + labor)
  const markup = round2(rows.reduce((sum, row) => sum + rowMarkup(row), 0))
  const subtotal = round2(base + markup)
  const tax = round2((subtotal * taxPct) / 100)

  return { material, labor, base, markup, subtotal, tax, total: round2(subtotal + tax) }
}

let counter = 0

/** A blank row, ready to be typed into. */
export function blankRow(defaults: { markupPct: number; laborRate: number }): BuilderRow {
  return {
    key: `new-${++counter}`,
    id: null,
    description: '',
    commodity: '',
    unit: 'EA',
    materialQty: 0,
    materialUnitPrice: 0,
    laborHours: 0,
    laborRate: defaults.laborRate,
    markupPct: defaults.markupPct,
    source: 'manual',
    sourceEstimateItemId: null,
    priceBookItemId: null,
  }
}

/** What the server takes: snake_case, numbers as numbers. */
export function toPayload(rows: readonly BuilderRow[]) {
  return rows.map((row) => ({
    id: row.id,
    description: row.description,
    commodity: row.commodity,
    unit: row.unit,
    material_qty: row.materialQty,
    material_unit_price: row.materialUnitPrice,
    labor_hours: row.laborHours,
    labor_rate: row.laborRate,
    markup_pct: row.markupPct,
    source: row.source,
    source_estimate_item_id: row.sourceEstimateItemId,
    price_book_item_id: row.priceBookItemId,
  }))
}
