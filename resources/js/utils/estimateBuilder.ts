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

  return {
    material,
    labor,
    base,
    markup,
    subtotal,
    tax,
    total: round2(subtotal + tax),
  }
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

/** What is still wrong with a row before the estimate can go for approval; null when it is fine. */
export function rowIssue(row: BuilderRow): string | null {
  if (row.description.trim() === '') return 'Needs a description'
  if (row.materialQty > 0 && row.materialUnitPrice <= 0) return 'Missing material price'
  if (row.laborHours > 0 && row.laborRate <= 0) return 'Missing labor rate'

  return null
}

/** Where allowances are grouped on the worksheet and in the summary. */
export const ALLOWANCE_COMMODITY = 'Allowances'

export type AllowanceBasis = 'materials' | 'labor' | 'both' | 'fixed'

export interface AllowanceInput {
  readonly name: string
  readonly basis: AllowanceBasis
  /** Percent of the basis, when the allowance is a percentage. */
  readonly pct: number
  /** The dollar amount, when the allowance is fixed. */
  readonly amount: number
}

/**
 * An allowance as one lump-sum row — waste, contingency, small tools, permits. A percentage
 * is worked out from the materials and labor on the sheet now (earlier allowances left out),
 * so what is saved is a plain amount the reviewer can read.
 */
export function allowanceRow(
  defaults: { laborRate: number },
  input: AllowanceInput,
  rows: readonly BuilderRow[],
): BuilderRow {
  const priced = rows.filter((row) => row.commodity !== ALLOWANCE_COMMODITY)
  const materials = priced.reduce((sum, row) => sum + rowMaterialTotal(row), 0)
  const labor = priced.reduce((sum, row) => sum + rowLaborTotal(row), 0)
  const base = input.basis === 'materials' ? materials : input.basis === 'labor' ? labor : materials + labor
  const label = input.basis === 'both' ? 'materials and labor' : input.basis
  const amount = input.basis === 'fixed' ? round2(input.amount) : round2((base * input.pct) / 100)

  return {
    ...blankRow({ markupPct: 0, laborRate: defaults.laborRate }),
    description: `Allowance — ${input.name.trim()}${input.basis === 'fixed' ? '' : ` (${input.pct}% of ${label})`}`,
    commodity: ALLOWANCE_COMMODITY,
    unit: 'LS',
    materialQty: 1,
    materialUnitPrice: amount,
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
