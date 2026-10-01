import { Head, router, usePage } from '@inertiajs/react'
import {
  Copy,
  FileDown,
  ListFilter,
  Lock,
  Pencil,
  Plus,
  Save,
  Search,
  Settings2,
  Trash2,
  Upload,
  X,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  EmptyState,
  FilterTabs,
  Modal,
  MoreMenu,
  SelectField,
  StatusChip,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps, Tone } from '@/types'
import {
  blankRow,
  builderTotals,
  cn,
  formatCurrency,
  round2,
  rowLaborTotal,
  rowMaterialTotal,
  rowSubtotal,
  toPayload,
  type BuilderRow,
} from '@/utils'

interface ServerLine {
  readonly id: number
  readonly description: string
  readonly commodity: string | null
  readonly unit: string
  readonly materialQty: number
  readonly materialUnitPrice: number
  readonly laborHours: number
  readonly laborRate: number
  readonly markupPct: number
  readonly source: BuilderRow['source']
  readonly sourceEstimateItemId: number | null
  readonly priceBookItemId: number | null
}

interface PriceItem {
  readonly id: number
  readonly description: string
  readonly unit: string
  readonly commodity: string | null
  readonly materialUnitPrice: number
  readonly hoursPerUnit: number
}

export interface EstimateBuilderProps {
  estimate: {
    readonly id: number
    readonly number: string
    readonly project: string
    readonly client: string
    readonly status: string
    /** True once it has been sent on: nothing here can be changed. */
    readonly locked: boolean
    readonly taxPct: number
    readonly markupPct: number
    readonly laborRate: number
    /** The price list it was priced from when last saved. */
    readonly commodityVersion: string | null
    /** What the estimate covers, and what it leaves out — read by whoever approves it. */
    /** How many times it has been sent for approval. */
    readonly revisions: number
    readonly scopeOfWork: string | null
    readonly exclusions: readonly string[]
    /** Set when a reviewer sent it back: what they wrote, who and when. */
    readonly returned: { readonly notes: string | null; readonly by: string | null; readonly at: string } | null
    readonly takeoffSource: { readonly id: number; readonly number: string; readonly project: string } | null
  }
  lines: readonly ServerLine[]
  priceList: readonly PriceItem[]
  /** The price list as it stands now. */
  priceListVersion: string
  importSources: readonly {
    readonly id: number
    readonly number: string
    readonly project: string
    readonly client: string
    /** An estimate of the project, or one of its addenda. */
    readonly kind: 'estimate' | 'addendum'
    readonly addendumNumber: number | null
    readonly addendumName: string | null
    readonly lines: number
  }[]
}

const STATUS: Record<string, { label: string; tone: Tone }> = {
  draft: { label: 'Draft', tone: 'neutral' },
  sent: { label: 'Awaiting approval', tone: 'warning' },
  approved: { label: 'Approved', tone: 'success' },
  rejected: { label: 'Rejected', tone: 'danger' },
}

type Tab = 'items' | 'pricing' | 'summary'

/**
 * Estimate Builder — quantities into priced labor and material lines.
 *
 * The estimator edits a worksheet: a row per thing being priced, material and labor
 * side by side, each with its own markup. Every figure is worked out from what is
 * typed and the totals follow every edit. Save Draft keeps the worksheet; Review
 * Estimate saves it and sends the estimate on for approval, which locks it.
 *
 * Re-keyed on what the server holds, so a save (which gives new rows their ids)
 * starts the page fresh from what was kept.
 */
export default function EstimateBuilder(props: EstimateBuilderProps) {
  return <Builder key={JSON.stringify([props.estimate.status, props.lines])} {...props} />
}

EstimateBuilder.layout = appLayout

function Builder({ estimate, lines, priceList, priceListVersion, importSources }: EstimateBuilderProps) {
  const { flash, errors } = usePage<SharedPageProps>().props
  const locked = estimate.locked

  const toRow = (line: ServerLine): BuilderRow => ({
    key: `saved-${line.id}`,
    id: line.id,
    description: line.description,
    commodity: line.commodity ?? '',
    unit: line.unit,
    materialQty: line.materialQty,
    materialUnitPrice: line.materialUnitPrice,
    laborHours: line.laborHours,
    laborRate: line.laborRate,
    markupPct: line.markupPct,
    source: line.source,
    sourceEstimateItemId: line.sourceEstimateItemId,
    priceBookItemId: line.priceBookItemId,
  })

  const [rows, setRows] = useState<BuilderRow[]>(() => lines.map(toRow))
  const [taxPct, setTaxPct] = useState(estimate.taxPct)
  const [markupPct, setMarkupPct] = useState(estimate.markupPct)
  const [laborRate, setLaborRate] = useState(estimate.laborRate)
  const [scope, setScope] = useState(estimate.scopeOfWork ?? '')
  const [exclusionsText, setExclusionsText] = useState(estimate.exclusions.join('\n'))
  const [tab, setTab] = useState<Tab>('items')
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set())
  const [showFilters, setShowFilters] = useState(false)
  const [search, setSearch] = useState('')
  const [commodityFilter, setCommodityFilter] = useState('')
  const [modal, setModal] = useState<'settings' | 'import' | 'markup' | 'commodity' | 'approve' | null>(null)
  const [busy, setBusy] = useState(false)
  const [priceSearch, setPriceSearch] = useState('')
  const [foundPrices, setFoundPrices] = useState<readonly PriceItem[] | null>(null)

  const totals = useMemo(() => builderTotals(rows, taxPct), [rows, taxPct])

  // Unsaved when what is on the page differs from what the server holds.
  const saved = useMemo(
    () =>
      JSON.stringify([
        toPayload(lines.map(toRow)),
        estimate.taxPct,
        estimate.markupPct,
        estimate.laborRate,
        estimate.scopeOfWork ?? '',
        estimate.exclusions.join('\n'),
      ]),
    [lines, estimate],
  )
  const current = JSON.stringify([toPayload(rows), taxPct, markupPct, laborRate, scope, exclusionsText])
  const dirty = saved !== current

  const commodities = useMemo(
    () =>
      [
        ...new Set(
          [...rows.map((row) => row.commodity), ...priceList.map((item) => item.commodity ?? '')].filter(Boolean),
        ),
      ].sort(),
    [rows, priceList],
  )

  const shown = rows.filter(
    (row) =>
      (search === '' || row.description.toLowerCase().includes(search.toLowerCase())) &&
      (commodityFilter === '' || row.commodity === commodityFilter),
  )

  const status = STATUS[estimate.status] ?? STATUS['draft']!
  const lineError = (errors['lines'] ?? Object.entries(errors).find(([key]) => key.startsWith('lines.'))?.[1]) as
    string | undefined
  const scopeError = errors['scope_of_work'] as string | undefined

  const change = (key: string, patch: Partial<BuilderRow>) =>
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)))

  const add = (row: BuilderRow) => setRows((current) => [...current, row])

  const payload = () => ({
    lines: toPayload(rows),
    settings: {
      tax_pct: taxPct,
      markup_pct: markupPct,
      labor_rate: laborRate,
      scope_of_work: scope,
      exclusions: exclusionsText
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean),
    },
  })

  const saveDraft = () =>
    router.put(routeTo.estimateBuilderShow(estimate.id), payload(), {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
    })

  const requestApproval = (revisionNote: string) =>
    router.post(
      routeTo.estimateBuilderRequestApproval(estimate.id),
      { ...payload(), revision_note: revisionNote },
      {
        onStart: () => setBusy(true),
        onFinish: () => {
          setBusy(false)
          setModal(null)
        },
      },
    )

  const remove = (keys: ReadonlySet<string>) => {
    setRows((current) => current.filter((row) => !keys.has(row.key)))
    setSelected(new Set())
  }

  const allShownSelected = shown.length > 0 && shown.every((row) => selected.has(row.key))
  const toggle = (key: string) =>
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)

      return next
    })

  const searchPrices = async (term: string) => {
    setPriceSearch(term)
    if (term.trim() === '') return setFoundPrices(null)
    const response = await fetch(`${routeTo.estimateBuilderPriceList}?search=${encodeURIComponent(term)}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (response.ok) setFoundPrices((await response.json()) as PriceItem[])
  }

  const addFromPriceList = (item: PriceItem) =>
    add({
      ...blankRow({ markupPct, laborRate }),
      description: item.description,
      commodity: item.commodity ?? '',
      unit: item.unit,
      materialQty: 1,
      materialUnitPrice: item.materialUnitPrice,
      laborHours: round2(item.hoursPerUnit),
      source: 'price-list',
      priceBookItemId: item.id,
    })

  const prices = foundPrices ?? priceList

  return (
    <PageTransition>
      <Head title="Estimate Builder" />

      <PageHeader
        title="Estimate Builder"
        breadcrumbs={[
          { label: 'Estimates', href: ROUTES.estimates },
          { label: 'Estimate Builder', href: ROUTES.estimateBuilder },
          { label: estimate.number },
        ]}
        actions={
          <>
            <Button variant="secondary" leftIcon={Settings2} disabled={locked} onClick={() => setModal('settings')}>
              Estimate Settings
            </Button>
            <Button
              variant="white"
              leftIcon={ListFilter}
              onClick={() => setShowFilters((open) => !open)}
              aria-pressed={showFilters}
            >
              Filters
            </Button>
            <MoreMenu
              ariaLabel="More"
              items={[
                { label: 'View estimate', icon: FileDown, onSelect: () => router.visit(routeTo.estimate(estimate.id)) },
                { label: 'All builder estimates', icon: Pencil, onSelect: () => router.visit(ROUTES.estimateBuilder) },
              ]}
            />
          </>
        }
      />

      {flash.success && (
        <Alert key={flash.success} tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}
      {flash.warning && (
        <Alert key={flash.warning} tone="warning" className="mb-4">
          {flash.warning}
        </Alert>
      )}
      {lineError && (
        <Alert tone="danger" className="mb-4">
          {lineError}
        </Alert>
      )}
      {scopeError && (
        <Alert tone="danger" className="mb-4">
          <span className="inline-flex flex-wrap items-center gap-x-3">
            {scopeError}
            <button type="button" className="font-semibold text-brand underline" onClick={() => setModal('settings')}>
              Open settings
            </button>
          </span>
        </Alert>
      )}
      {estimate.returned && !locked && (
        <Alert tone="warning" className="mb-4" title="Sent back for edits">
          {estimate.returned.by
            ? `${estimate.returned.by} returned this estimate`
            : 'A reviewer returned this estimate'}
          {estimate.returned.notes ? `: “${estimate.returned.notes}”` : '.'} Fix it, then send it for approval again.
        </Alert>
      )}
      {locked && (
        <Alert tone="info" className="mb-4" title="This estimate has been sent">
          <span className="inline-flex flex-wrap items-center gap-x-3">
            <Lock size={14} aria-hidden /> It is with review and approval, so the worksheet can no longer be changed.
            <a href={routeTo.estimateReview(estimate.id)} className="font-semibold text-brand underline">
              Open the review
            </a>
          </span>
        </Alert>
      )}

      <Card padding="none" animated={false} className="overflow-hidden">
        {/* ------------------------------------------------------------ Estimate title -- */}
        <div className="border-b border-hairline px-5 py-4 sm:px-6">
          <div className="flex flex-wrap items-center gap-3">
            <h2 className="text-2xl font-bold text-white">{estimate.project}</h2>
            <StatusChip pill tone={status.tone} label={status.label} />
            {dirty && !locked && <StatusChip pill tone="warning" label="Unsaved changes" pulse />}
          </div>
          <p className="mt-1 text-sm text-white/75">
            Client: {estimate.client} <span className="mx-2 text-white/40">|</span> Estimate: {estimate.number}
            {estimate.takeoffSource && (
              <>
                <span className="mx-2 text-white/40">|</span> Takeoff: {estimate.takeoffSource.number}
              </>
            )}
          </p>
        </div>

        {/* ------------------------------------------------------------------ Tabs -- */}
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3 sm:px-6">
          <FilterTabs<Tab>
            value={tab}
            onChange={setTab}
            options={[
              { label: 'Takeoff Items', value: 'items' },
              { label: 'Commodity Pricing', value: 'pricing' },
              { label: 'Summary', value: 'summary' },
            ]}
          />

          {tab === 'items' && !locked && (
            <div className="flex flex-wrap items-center gap-2">
              <Button variant="blue" leftIcon={Plus} onClick={() => add(blankRow({ markupPct, laborRate }))}>
                Add Item
              </Button>
              <Button variant="secondary" leftIcon={Upload} onClick={() => setModal('import')}>
                Import Takeoff
              </Button>
              <MoreMenu
                label="Bulk Actions"
                ariaLabel="Bulk actions"
                disabled={selected.size === 0}
                items={[
                  { label: `Set markup (${selected.size})`, icon: Pencil, onSelect: () => setModal('markup') },
                  { label: `Set commodity (${selected.size})`, icon: Pencil, onSelect: () => setModal('commodity') },
                  {
                    label: `Delete (${selected.size})`,
                    icon: Trash2,
                    destructive: true,
                    onSelect: () => remove(selected),
                  },
                ]}
              />
            </div>
          )}
        </div>

        {showFilters && tab === 'items' && (
          <div className="flex flex-wrap items-end gap-3 border-b border-hairline bg-white/3 px-5 py-3 sm:px-6">
            <TextInput
              id="builder-search"
              label="Search items"
              leftIcon={Search}
              className="w-64"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
            <SelectField
              id="builder-commodity-filter"
              label="Commodity"
              className="w-56"
              options={[
                { value: '', label: 'All commodities' },
                ...commodities.map((name) => ({ value: name, label: name })),
              ]}
              value={commodityFilter}
              onChange={(event) => setCommodityFilter(event.target.value)}
            />
            <Button
              variant="secondary"
              leftIcon={X}
              disabled={search === '' && commodityFilter === ''}
              onClick={() => {
                setSearch('')
                setCommodityFilter('')
              }}
            >
              Clear
            </Button>
          </div>
        )}

        {/* ------------------------------------------------------------- Takeoff items -- */}
        {tab === 'items' && (
          <div className="p-5 sm:p-6">
            {rows.length === 0 ? (
              <EmptyState
                icon={Plus}
                title="Nothing to price yet"
                description="Add an item, copy in a takeoff, or add rows from the Commodity Pricing tab."
                {...(!locked
                  ? {
                      actions: (
                        <Button leftIcon={Plus} onClick={() => add(blankRow({ markupPct, laborRate }))}>
                          Add Item
                        </Button>
                      ),
                    }
                  : {})}
              />
            ) : (
              <div className="overflow-x-auto rounded-panel border border-hairline">
                <table className="w-full min-w-[78rem] text-left text-sm">
                  <thead>
                    <tr className="border-b border-hairline bg-white/4 text-xs text-white/80">
                      <th className="w-10 px-3 py-3">
                        <input
                          type="checkbox"
                          aria-label="Select all shown items"
                          disabled={locked}
                          checked={allShownSelected}
                          onChange={() =>
                            setSelected(allShownSelected ? new Set() : new Set(shown.map((row) => row.key)))
                          }
                          className="size-4 accent-[var(--color-brand)]"
                        />
                      </th>
                      <th className="w-8 px-1 py-3 font-semibold">#</th>
                      <th className="min-w-56 px-2 py-3 font-semibold">Description</th>
                      <th className="min-w-36 px-2 py-3 font-semibold">Commodity</th>
                      <th className="w-20 px-2 py-3 font-semibold">Unit</th>
                      <th className="w-28 px-2 py-3 text-right font-semibold">Material Qty</th>
                      <th className="w-28 px-2 py-3 text-right font-semibold">Material Unit $</th>
                      <th className="w-28 px-2 py-3 text-right font-semibold">Material Total</th>
                      <th className="w-28 px-2 py-3 text-right font-semibold">Labor Qty (Hrs)</th>
                      <th className="w-28 px-2 py-3 text-right font-semibold">Labor Rate $</th>
                      <th className="w-28 px-2 py-3 text-right font-semibold">Labor Total</th>
                      <th className="w-24 px-2 py-3 text-right font-semibold">Markup %</th>
                      <th className="w-32 px-2 py-3 text-right font-semibold">Subtotal</th>
                      <th className="w-12 px-2 py-3" />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-hairline">
                    {shown.map((row) => {
                      const index = rows.indexOf(row) + 1

                      return (
                        <tr
                          key={row.key}
                          className={cn('transition-colors hover:bg-white/4', selected.has(row.key) && 'bg-brand/8')}
                        >
                          <td className="px-3 py-2">
                            <input
                              type="checkbox"
                              aria-label={`Select item ${index}`}
                              disabled={locked}
                              checked={selected.has(row.key)}
                              onChange={() => toggle(row.key)}
                              className="size-4 accent-[var(--color-brand)]"
                            />
                          </td>
                          <td className="px-1 py-2 text-white/70 tabular-nums">{index}</td>
                          <td className="px-2 py-1.5">
                            <CellText
                              label={`Description, item ${index}`}
                              value={row.description}
                              disabled={locked}
                              onChange={(value) => change(row.key, { description: value })}
                              placeholder="Describe the item"
                            />
                            {row.source === 'takeoff' && (
                              <span className="ml-2 text-2xs text-brand/80">from takeoff</span>
                            )}
                          </td>
                          <td className="px-2 py-1.5">
                            <CellText
                              label={`Commodity, item ${index}`}
                              value={row.commodity}
                              disabled={locked}
                              onChange={(value) => change(row.key, { commodity: value })}
                              list="builder-commodities"
                            />
                          </td>
                          <td className="px-2 py-1.5">
                            <CellText
                              label={`Unit, item ${index}`}
                              value={row.unit}
                              disabled={locked}
                              onChange={(value) => change(row.key, { unit: value.toUpperCase() })}
                              maxLength={20}
                            />
                          </td>
                          <td className="px-2 py-1.5">
                            <CellNumber
                              label={`Material quantity, item ${index}`}
                              value={row.materialQty}
                              disabled={locked}
                              onChange={(value) => change(row.key, { materialQty: value })}
                            />
                          </td>
                          <td className="px-2 py-1.5">
                            <CellNumber
                              label={`Material unit price, item ${index}`}
                              value={row.materialUnitPrice}
                              money
                              disabled={locked}
                              onChange={(value) => change(row.key, { materialUnitPrice: value })}
                            />
                          </td>
                          <td className="px-2 py-2 text-right tabular-nums text-white/90">
                            {formatCurrency(rowMaterialTotal(row), 2)}
                          </td>
                          <td className="px-2 py-1.5">
                            <CellNumber
                              label={`Labor hours, item ${index}`}
                              value={row.laborHours}
                              disabled={locked}
                              onChange={(value) => change(row.key, { laborHours: value })}
                            />
                          </td>
                          <td className="px-2 py-1.5">
                            <CellNumber
                              label={`Labor rate, item ${index}`}
                              value={row.laborRate}
                              money
                              disabled={locked}
                              onChange={(value) => change(row.key, { laborRate: value })}
                            />
                          </td>
                          <td className="px-2 py-2 text-right tabular-nums text-white/90">
                            {formatCurrency(rowLaborTotal(row), 2)}
                          </td>
                          <td className="px-2 py-1.5">
                            <CellNumber
                              label={`Markup percent, item ${index}`}
                              value={row.markupPct}
                              suffix="%"
                              disabled={locked}
                              onChange={(value) => change(row.key, { markupPct: value })}
                            />
                          </td>
                          <td className="px-2 py-2 text-right font-semibold tabular-nums text-white">
                            {formatCurrency(rowSubtotal(row), 2)}
                          </td>
                          <td className="px-2 py-1">
                            {!locked && (
                              <MoreMenu
                                variant="minimal"
                                ariaLabel={`Item ${index} actions`}
                                items={[
                                  {
                                    label: 'Duplicate',
                                    icon: Copy,
                                    onSelect: () =>
                                      add({ ...row, key: blankRow({ markupPct, laborRate }).key, id: null }),
                                  },
                                  {
                                    label: 'Delete',
                                    icon: Trash2,
                                    destructive: true,
                                    onSelect: () => remove(new Set([row.key])),
                                  },
                                ]}
                              />
                            )}
                          </td>
                        </tr>
                      )
                    })}
                    {shown.length === 0 && (
                      <tr>
                        <td colSpan={14} className="px-4 py-8 text-center text-white/70">
                          No item matches the filters.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
                <datalist id="builder-commodities">
                  {commodities.map((name) => (
                    <option key={name} value={name} />
                  ))}
                </datalist>
              </div>
            )}

            <div className="mt-5 flex flex-wrap items-start justify-between gap-5">
              {!locked ? (
                <Button variant="secondary" leftIcon={Plus} onClick={() => add(blankRow({ markupPct, laborRate }))}>
                  Add Takeoff Item
                </Button>
              ) : (
                <span />
              )}

              <TotalsCard totals={totals} taxPct={taxPct} />
            </div>

            <div className="mt-5 flex flex-wrap items-center justify-end gap-3">
              <Button
                variant="white"
                size="lg"
                leftIcon={Save}
                isLoading={busy}
                disabled={locked || !dirty}
                onClick={saveDraft}
              >
                Save Draft
              </Button>
              <Button
                variant="blue"
                size="lg"
                disabled={locked || rows.length === 0 || busy}
                onClick={() => setModal('approve')}
              >
                Review Estimate
              </Button>
            </div>
          </div>
        )}

        {/* -------------------------------------------------------- Commodity pricing -- */}
        {tab === 'pricing' && (
          <div className="p-5 sm:p-6">
            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
              <div>
                <h3 className="text-lg font-semibold text-white">Commodity list prices</h3>
                <p className="text-sm text-white/70">{priceListVersion}</p>
              </div>
              <TextInput
                id="builder-price-search"
                label="Search the price list"
                leftIcon={Search}
                className="w-72"
                value={priceSearch}
                onChange={(event) => void searchPrices(event.target.value)}
              />
            </div>

            {prices.length === 0 ? (
              <EmptyState
                icon={Search}
                title="No prices to show"
                description={
                  priceSearch ? 'Nothing in the price list matches that.' : 'This company has no price list yet.'
                }
              />
            ) : (
              <div className="overflow-x-auto rounded-panel border border-hairline">
                <table className="w-full min-w-3xl text-left text-sm">
                  <thead>
                    <tr className="border-b border-hairline bg-white/4 text-xs text-white/80">
                      <th className="px-3 py-3 font-semibold">Description</th>
                      <th className="px-3 py-3 font-semibold">Commodity</th>
                      <th className="px-3 py-3 font-semibold">Unit</th>
                      <th className="px-3 py-3 text-right font-semibold">Material $ / unit</th>
                      <th className="px-3 py-3 text-right font-semibold">Hours / unit</th>
                      <th className="px-3 py-3" />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-hairline">
                    {prices.map((item) => (
                      <tr key={item.id} className="hover:bg-white/4">
                        <td className="px-3 py-2 text-white">{item.description}</td>
                        <td className="px-3 py-2 text-white/85">{item.commodity ?? '—'}</td>
                        <td className="px-3 py-2 text-white/85">{item.unit}</td>
                        <td className="px-3 py-2 text-right tabular-nums text-white">
                          {formatCurrency(item.materialUnitPrice, 4).replace(/0{1,2}$/, '')}
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums text-white">{item.hoursPerUnit}</td>
                        <td className="px-3 py-2 text-right">
                          <Button
                            size="sm"
                            variant="secondary"
                            leftIcon={Plus}
                            disabled={locked}
                            onClick={() => addFromPriceList(item)}
                          >
                            Add
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <p className="mt-3 text-xs text-white/60">
              Adding a row copies its price and hours onto the worksheet; change the quantity on the Takeoff Items tab
              and the totals follow.
            </p>
          </div>
        )}

        {/* -------------------------------------------------------------------- Summary -- */}
        {tab === 'summary' && (
          <SummaryTab
            rows={rows}
            totals={totals}
            taxPct={taxPct}
            estimate={estimate}
            priceListVersion={priceListVersion}
          />
        )}
      </Card>

      {/* ----------------------------------------------------------------- Modals ---- */}
      {modal === 'settings' && (
        <SettingsModal
          taxPct={taxPct}
          markupPct={markupPct}
          laborRate={laborRate}
          scope={scope}
          exclusions={exclusionsText}
          onClose={() => setModal(null)}
          onSave={(next) => {
            setTaxPct(next.taxPct)
            setMarkupPct(next.markupPct)
            setLaborRate(next.laborRate)
            setScope(next.scope)
            setExclusionsText(next.exclusions)
            setModal(null)
          }}
        />
      )}
      {modal === 'import' && (
        <ImportModal
          estimateId={estimate.id}
          project={estimate.project}
          sources={importSources}
          dirty={dirty}
          onClose={() => setModal(null)}
        />
      )}
      {modal === 'markup' && (
        <NumberModal
          title={`Set markup for ${selected.size} ${selected.size === 1 ? 'item' : 'items'}`}
          label="Markup %"
          initial={markupPct}
          onClose={() => setModal(null)}
          onSave={(value) => {
            setRows((current) => current.map((row) => (selected.has(row.key) ? { ...row, markupPct: value } : row)))
            setModal(null)
          }}
        />
      )}
      {modal === 'commodity' && (
        <TextModal
          title={`Set commodity for ${selected.size} ${selected.size === 1 ? 'item' : 'items'}`}
          label="Commodity"
          options={commodities}
          onClose={() => setModal(null)}
          onSave={(value) => {
            setRows((current) => current.map((row) => (selected.has(row.key) ? { ...row, commodity: value } : row)))
            setModal(null)
          }}
        />
      )}
      {modal === 'approve' && (
        <ApproveModal
          number={estimate.number}
          total={totals.total}
          firstVersion={estimate.revisions === 0}
          busy={busy}
          missingScope={scope.trim() === ''}
          onOpenSettings={() => setModal('settings')}
          onClose={() => setModal(null)}
          onConfirm={requestApproval}
        />
      )}
    </PageTransition>
  )
}

/** Subtotal, tax and total — the figures the estimate ends up with. */
function TotalsCard({ totals, taxPct }: { totals: ReturnType<typeof builderTotals>; taxPct: number }) {
  return (
    <div className="w-full rounded-panel border border-hairline bg-white/4 sm:w-96">
      <dl className="space-y-2 px-4 py-3 text-sm">
        <div className="flex justify-between gap-4 text-white/85">
          <dt>Subtotal</dt>
          <dd className="tabular-nums">{formatCurrency(totals.subtotal, 2)}</dd>
        </div>
        <div className="flex justify-between gap-4 text-white/85">
          <dt>Tax ({taxPct}%)</dt>
          <dd className="tabular-nums">{formatCurrency(totals.tax, 2)}</dd>
        </div>
      </dl>
      <div className="flex items-baseline justify-between gap-4 border-t border-hairline px-4 py-3">
        <span className="text-md font-semibold text-white">Total</span>
        <span className="text-2xl font-bold tabular-nums text-white" aria-live="polite">
          {formatCurrency(totals.total, 2)}
        </span>
      </div>
    </div>
  )
}

function SummaryTab({
  rows,
  totals,
  taxPct,
  estimate,
  priceListVersion,
}: {
  rows: readonly BuilderRow[]
  totals: ReturnType<typeof builderTotals>
  taxPct: number
  estimate: EstimateBuilderProps['estimate']
  priceListVersion: string
}) {
  const byCommodity = [
    ...rows.reduce((map, row) => {
      const name = row.commodity || 'Uncategorised'
      const entry = map.get(name) ?? { items: 0, material: 0, labor: 0, subtotal: 0 }
      map.set(name, {
        items: entry.items + 1,
        material: round2(entry.material + rowMaterialTotal(row)),
        labor: round2(entry.labor + rowLaborTotal(row)),
        subtotal: round2(entry.subtotal + rowSubtotal(row)),
      })

      return map
    }, new Map<string, { items: number; material: number; labor: number; subtotal: number }>()),
  ].sort(([a], [b]) => a.localeCompare(b))

  const tiles: readonly [string, number][] = [
    ['Material', totals.material],
    ['Labor', totals.labor],
    ['Markup', totals.markup],
    ['Subtotal', totals.subtotal],
    [`Tax (${taxPct}%)`, totals.tax],
    ['Total', totals.total],
  ]

  return (
    <div className="space-y-6 p-5 sm:p-6">
      <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
        {tiles.map(([label, value]) => (
          <div
            key={label}
            className={cn(
              'rounded-panel border border-hairline bg-white/4 px-4 py-3',
              label === 'Total' && 'border-brand/50 bg-brand/10',
            )}
          >
            <p className="text-xs text-white/70">{label}</p>
            <p className="mt-1 text-xl font-bold tabular-nums text-white">{formatCurrency(value, 2)}</p>
          </div>
        ))}
      </div>

      <div>
        <h3 className="mb-3 text-lg font-semibold text-white">By commodity</h3>
        {byCommodity.length === 0 ? (
          <p className="text-sm text-white/70">Nothing has been priced yet.</p>
        ) : (
          <div className="overflow-x-auto rounded-panel border border-hairline">
            <table className="w-full min-w-2xl text-left text-sm">
              <thead>
                <tr className="border-b border-hairline bg-white/4 text-xs text-white/80">
                  <th className="px-3 py-3 font-semibold">Commodity</th>
                  <th className="px-3 py-3 text-center font-semibold">Items</th>
                  <th className="px-3 py-3 text-right font-semibold">Material</th>
                  <th className="px-3 py-3 text-right font-semibold">Labor</th>
                  <th className="px-3 py-3 text-right font-semibold">Subtotal</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-hairline">
                {byCommodity.map(([name, entry]) => (
                  <tr key={name}>
                    <td className="px-3 py-2 text-white">{name}</td>
                    <td className="px-3 py-2 text-center tabular-nums text-white/85">{entry.items}</td>
                    <td className="px-3 py-2 text-right tabular-nums text-white/85">
                      {formatCurrency(entry.material, 2)}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums text-white/85">
                      {formatCurrency(entry.labor, 2)}
                    </td>
                    <td className="px-3 py-2 text-right font-semibold tabular-nums text-white">
                      {formatCurrency(entry.subtotal, 2)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div>
        <h3 className="mb-3 text-lg font-semibold text-white">Where it came from</h3>
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <div className="rounded-panel border border-hairline bg-white/4 px-4 py-3">
            <dt className="text-xs text-white/70">Takeoff source</dt>
            <dd className="mt-1 text-white">
              {estimate.takeoffSource
                ? `${estimate.takeoffSource.number} — ${estimate.takeoffSource.project}`
                : 'Priced by hand — no takeoff copied in'}
            </dd>
          </div>
          <div className="rounded-panel border border-hairline bg-white/4 px-4 py-3">
            <dt className="text-xs text-white/70">Commodity list version</dt>
            <dd className="mt-1 text-white">{estimate.commodityVersion ?? 'Not saved yet'}</dd>
            {estimate.commodityVersion && estimate.commodityVersion !== priceListVersion && (
              <dd className="mt-1 text-xs text-status-warning">
                The price list has changed since this was saved: now {priceListVersion}.
              </dd>
            )}
          </div>
        </dl>
      </div>
    </div>
  )
}

const CELL =
  'w-full rounded-md border border-transparent bg-transparent px-2 py-1.5 text-sm text-white placeholder:text-white/40 ' +
  'hover:border-hairline-strong focus:border-brand focus:bg-white/8 focus:outline-none disabled:cursor-not-allowed disabled:opacity-70'

function CellText({
  label,
  value,
  onChange,
  disabled,
  ...rest
}: {
  label: string
  value: string
  onChange: (value: string) => void
  disabled: boolean
  placeholder?: string
  list?: string
  maxLength?: number
}) {
  return (
    <input
      aria-label={label}
      value={value}
      disabled={disabled}
      onChange={(event) => onChange(event.target.value)}
      className={CELL}
      {...rest}
    />
  )
}

function CellNumber({
  label,
  value,
  onChange,
  disabled,
  money,
  suffix,
}: {
  label: string
  value: number
  onChange: (value: number) => void
  disabled: boolean
  money?: boolean
  suffix?: string
}) {
  return (
    <span className="relative block">
      {money && (
        <span aria-hidden className="pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-white/50">
          $
        </span>
      )}
      <input
        type="number"
        inputMode="decimal"
        min={0}
        step="any"
        aria-label={label}
        disabled={disabled}
        value={Number.isFinite(value) ? value : ''}
        onChange={(event) => onChange(event.target.value === '' ? 0 : Math.max(0, Number(event.target.value)))}
        className={cn(CELL, 'text-right tabular-nums', money && 'pl-5', suffix && 'pr-6')}
      />
      {suffix && (
        <span aria-hidden className="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-white/50">
          {suffix}
        </span>
      )}
    </span>
  )
}

function SettingsModal({
  taxPct,
  markupPct,
  laborRate,
  scope,
  exclusions,
  onClose,
  onSave,
}: {
  taxPct: number
  markupPct: number
  laborRate: number
  scope: string
  exclusions: string
  onClose: () => void
  onSave: (next: { taxPct: number; markupPct: number; laborRate: number; scope: string; exclusions: string }) => void
}) {
  const [tax, setTax] = useState(String(taxPct))
  const [markup, setMarkup] = useState(String(markupPct))
  const [rate, setRate] = useState(String(laborRate))
  const [scopeText, setScopeText] = useState(scope)
  const [exclusionText, setExclusionText] = useState(exclusions)

  const valid = (value: string, max: number) => value !== '' && Number(value) >= 0 && Number(value) <= max

  return (
    <Modal
      isOpen
      onClose={onClose}
      size="lg"
      title="Estimate settings"
      description="Applied to the estimate, and used for new items. Rows already on the worksheet keep their own markup and rate."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            disabled={!valid(tax, 100) || !valid(markup, 1000) || !valid(rate, 100000)}
            onClick={() =>
              onSave({
                taxPct: Number(tax),
                markupPct: Number(markup),
                laborRate: Number(rate),
                scope: scopeText,
                exclusions: exclusionText,
              })
            }
          >
            Apply
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-3">
        <TextInput
          id="setting-tax"
          label="Tax %"
          type="number"
          min={0}
          max={100}
          step="any"
          value={tax}
          onChange={(event) => setTax(event.target.value)}
        />
        <TextInput
          id="setting-markup"
          label="Default markup %"
          type="number"
          min={0}
          step="any"
          value={markup}
          onChange={(event) => setMarkup(event.target.value)}
        />
        <TextInput
          id="setting-rate"
          label="Default labor rate $/hr"
          type="number"
          min={0}
          step="any"
          value={rate}
          onChange={(event) => setRate(event.target.value)}
        />
      </div>
      <div className="mt-5 space-y-4">
        <TextArea
          id="setting-scope"
          label="Scope of work"
          rows={4}
          maxLength={4000}
          placeholder="What this estimate includes — the reviewer reads this before approving."
          value={scopeText}
          onChange={(event) => setScopeText(event.target.value)}
        />
        <TextArea
          id="setting-exclusions"
          label="Exclusions (one per line)"
          rows={6}
          placeholder="What this estimate does not cover."
          value={exclusionText}
          onChange={(event) => setExclusionText(event.target.value)}
        />
      </div>
    </Modal>
  )
}

/** Sending the estimate for approval: what it comes to, and what changed in this version. */
function ApproveModal({
  number,
  total,
  firstVersion,
  busy,
  missingScope,
  onOpenSettings,
  onClose,
  onConfirm,
}: {
  number: string
  total: number
  firstVersion: boolean
  busy: boolean
  missingScope: boolean
  onOpenSettings: () => void
  onClose: () => void
  onConfirm: (note: string) => void
}) {
  const [note, setNote] = useState('')

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Send this estimate for approval?"
      description={`${number} — ${formatCurrency(total, 2)}. It will be saved and sent to review and approval, and the worksheet will be locked.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button isLoading={busy} disabled={missingScope} onClick={() => onConfirm(note)}>
            Review Estimate
          </Button>
        </>
      }
    >
      {missingScope && (
        <Alert tone="warning" className="mb-4">
          A reviewer verifies the scope, so it has to be written first.{' '}
          <button type="button" className="font-semibold text-brand underline" onClick={onOpenSettings}>
            Open settings
          </button>
        </Alert>
      )}
      <TextArea
        id="revision-note"
        label={firstVersion ? 'Note for the reviewer (optional)' : 'What changed in this version? (optional)'}
        rows={3}
        maxLength={200}
        placeholder={firstVersion ? 'Initial estimate' : 'Left blank, the change in the figures is recorded.'}
        value={note}
        onChange={(event) => setNote(event.target.value)}
        addon={`${note.length}/200`}
      />
    </Modal>
  )
}

function ImportModal({
  estimateId,
  project,
  sources,
  dirty,
  onClose,
}: {
  estimateId: number
  project: string
  sources: EstimateBuilderProps['importSources']
  dirty: boolean
  onClose: () => void
}) {
  const [source, setSource] = useState('')
  const [busy, setBusy] = useState(false)

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Import takeoff"
      description="Copies the lines of this project's estimates and addenda onto the worksheet. The original is not changed, and lines already copied are skipped."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            isLoading={busy}
            disabled={source === '' || dirty}
            onClick={() =>
              router.post(
                routeTo.estimateBuilderImport(estimateId),
                { source_estimate_id: Number(source) },
                {
                  preserveScroll: true,
                  onStart: () => setBusy(true),
                  onFinish: () => {
                    setBusy(false)
                    onClose()
                  },
                },
              )
            }
          >
            Import
          </Button>
        </>
      }
    >
      {dirty && (
        <Alert tone="warning" className="mb-4">
          Save your draft first — importing reloads the worksheet and would lose unsaved changes.
        </Alert>
      )}
      {sources.length === 0 ? (
        <p className="text-sm text-white/75">{project} has no other estimate or addendum with lines to copy.</p>
      ) : (
        <SelectField
          id="import-source"
          label={`Copy lines from ${project}`}
          options={[
            { value: '', label: 'Select an estimate or addendum' },
            ...sources.map((item) => ({
              value: String(item.id),
              label:
                item.kind === 'addendum'
                  ? `${item.number} — Addendum ${item.addendumNumber ?? ''}${item.addendumName ? `: ${item.addendumName}` : ''} (${item.lines} lines)`
                  : `${item.number} — Estimate (${item.lines} lines)`,
            })),
          ]}
          value={source}
          onChange={(event) => setSource(event.target.value)}
        />
      )}
    </Modal>
  )
}

function NumberModal({
  title,
  label,
  initial,
  onClose,
  onSave,
}: {
  title: string
  label: string
  initial: number
  onClose: () => void
  onSave: (value: number) => void
}) {
  const [value, setValue] = useState(String(initial))

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={title}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            disabled={value === '' || Number(value) < 0 || Number(value) > 1000}
            onClick={() => onSave(Number(value))}
          >
            Apply
          </Button>
        </>
      }
    >
      <TextInput
        id="bulk-number"
        label={label}
        type="number"
        min={0}
        step="any"
        autoFocus
        value={value}
        onChange={(event) => setValue(event.target.value)}
      />
    </Modal>
  )
}

function TextModal({
  title,
  label,
  options,
  onClose,
  onSave,
}: {
  title: string
  label: string
  options: readonly string[]
  onClose: () => void
  onSave: (value: string) => void
}) {
  const [value, setValue] = useState('')

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={title}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button disabled={value.trim() === ''} onClick={() => onSave(value.trim())}>
            Apply
          </Button>
        </>
      }
    >
      <TextInput
        id="bulk-text"
        label={label}
        list="bulk-commodities"
        autoFocus
        maxLength={120}
        value={value}
        onChange={(event) => setValue(event.target.value)}
      />
      <datalist id="bulk-commodities">
        {options.map((name) => (
          <option key={name} value={name} />
        ))}
      </datalist>
    </Modal>
  )
}
