import { Head, router, usePage } from '@inertiajs/react'
import {
  Archive,
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  Download,
  Pencil,
  Plus,
  RotateCcw,
  Save,
  Search,
  Upload,
} from 'lucide-react'
import { useRef, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  EmptyState,
  Modal,
  MoreMenu,
  Pagination,
  SelectField,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps } from '@/types'
import { cn } from '@/utils'

interface CommodityRow {
  readonly id: number
  readonly category: string
  readonly itemCode: string
  readonly description: string
  readonly unit: string
  readonly materialPrice: number
  readonly laborHours: number
  readonly markupPct: number | null
  readonly archived: boolean
}

export interface CommodityListProps {
  items: readonly CommodityRow[]
  page: { current: number; last: number; total: number; from: number; to: number }
  filters: { search: string; category: string; archived: boolean; sort: string; dir: 'asc' | 'desc' }
  categories: readonly string[]
  counts: { active: number; archived: number }
  /** True while the company is still getting started — the list may be skipped and saved from here. */
  settingUp: boolean
  /** The file types an upload is read from. */
  formats: readonly string[]
}

type FormState = {
  category: string
  item_code: string
  description: string
  unit: string
  material_price: string
  labor_hours: string
  markup_pct: string
}

const BLANK: FormState = {
  category: '',
  item_code: '',
  description: '',
  unit: '',
  material_price: '',
  labor_hours: '',
  markup_pct: '',
}

const COLUMNS = [
  { key: 'category', label: 'Category', align: 'left' },
  { key: 'item_code', label: 'Item Code', align: 'left' },
  { key: 'description', label: 'Description', align: 'left' },
  { key: 'unit', label: 'Unit', align: 'left' },
  { key: 'material_price', label: 'Material Price ($)', align: 'right' },
  { key: 'labor_hours', label: 'Labor Hours', align: 'right' },
] as const

const money = (value: number) => value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/**
 * Commodity List Setup: the company's default list of the materials and labor it prices most
 * often. Items are saved as they are added; nothing here has to be complete during setup.
 */
export default function CommodityList({
  items,
  page,
  filters,
  categories,
  counts,
  settingUp,
  formats,
}: CommodityListProps) {
  const { flash, errors } = usePage<SharedPageProps>().props
  const [search, setSearch] = useState(filters.search)
  const [editing, setEditing] = useState<CommodityRow | 'new' | null>(null)
  const [importing, setImporting] = useState(false)

  const visit = (changes: Record<string, string | number | boolean | null>) => {
    const next = { ...filters, page: page.current, ...changes }
    const query: Record<string, string | number> = {}
    if (next.search) query['search'] = next.search
    if (next.category) query['category'] = next.category
    if (next.archived) query['archived'] = 1
    if (next.sort !== 'category' || next.dir !== 'asc') {
      query['sort'] = next.sort
      query['dir'] = next.dir
    }
    if (Number(next.page) > 1 && !('search' in changes || 'category' in changes || 'archived' in changes))
      query['page'] = Number(next.page)

    router.get(ROUTES.commodities, query, { preserveState: true, preserveScroll: true, replace: true })
  }

  const sortBy = (key: string) =>
    visit({ sort: key, dir: filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc', page: 1 })

  const save = () => router.post(routeTo.commoditySave, {}, { preserveScroll: true })

  return (
    <PageTransition>
      <Head title="Commodity List Setup" />

      <Card padding="none" className="overflow-hidden">
        <PageHeader
          title="Commodity List Setup"
          subtitle="Manage your material and labor items for estimates. Create, edit, and organize your commodity list. Projects with no rate list of their own are priced from it."
          className="mb-0 border-b border-hairline p-4 sm:p-5"
          actions={
            <>
              <Button variant="outline" leftIcon={Upload} onClick={() => setImporting(true)}>
                Upload List
              </Button>
              <Button variant="secondary" leftIcon={Plus} onClick={() => setEditing('new')}>
                Add Item
              </Button>
              <Button leftIcon={Save} onClick={save}>
                Save Default List
              </Button>
            </>
          }
        />

        <div className="p-4 sm:p-5">
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
          {settingUp && (
            <Alert tone="info" className="mb-4">
              <span className="flex flex-wrap items-center justify-between gap-3">
                <span>
                  Part of setting up your workspace — you don't have to finish it now. Items you add are kept.
                </span>
                <Button variant="ghost" size="sm" onClick={() => router.post(routeTo.commoditySkip)}>
                  Skip and add later
                </Button>
              </span>
            </Alert>
          )}

          <form
            className="mb-4 flex flex-wrap items-center justify-between gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              visit({ search: search.trim(), page: 1 })
            }}
          >
            <TextInput
              id="commodity-search"
              aria-label="Search by category, item code, or description"
              placeholder="Search by category, item code, or description..."
              leftIcon={Search}
              className="w-full sm:max-w-xl sm:flex-1"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              onBlur={() => search.trim() !== filters.search && visit({ search: search.trim(), page: 1 })}
            />
            <div className="flex flex-wrap items-center gap-3">
              <SelectField
                aria-label="Filter by category"
                className="w-48"
                value={filters.category}
                onChange={(event) => visit({ category: event.target.value, page: 1 })}
                options={[
                  { value: '', label: 'All Categories' },
                  ...categories.map((name) => ({ value: name, label: name })),
                ]}
              />
              {counts.archived > 0 && (
                <Button
                  type="button"
                  variant={filters.archived ? 'secondary' : 'ghost'}
                  leftIcon={Archive}
                  onClick={() => visit({ archived: !filters.archived, page: 1 })}
                >
                  {filters.archived ? 'Back to the list' : `Archived (${counts.archived})`}
                </Button>
              )}
            </div>
          </form>

          {items.length === 0 ? (
            <EmptyState
              title={
                filters.search || filters.category
                  ? 'No items match'
                  : filters.archived
                    ? 'Nothing archived'
                    : 'Your commodity list is empty'
              }
              description={
                filters.search || filters.category
                  ? 'Try a different search or category.'
                  : filters.archived
                    ? 'Archived items appear here, and can be restored.'
                    : 'Add items one by one, or upload the price list you already have — Excel, PDF, Word or CSV — and it is read for you.'
              }
              {...(!filters.search && !filters.category && !filters.archived
                ? {
                    action: (
                      <div className="flex flex-wrap justify-center gap-3">
                        <Button leftIcon={Plus} onClick={() => setEditing('new')}>
                          Add Item
                        </Button>
                        <Button variant="outline" leftIcon={Upload} onClick={() => setImporting(true)}>
                          Upload List
                        </Button>
                      </div>
                    ),
                  }
                : {})}
            />
          ) : (
            <div className="overflow-x-auto rounded-panel border border-hairline">
              <table className="w-full min-w-[52rem] text-left text-sm">
                <thead>
                  <tr className="border-b border-hairline bg-white/6 text-xs font-semibold text-white/90">
                    {COLUMNS.map((column) => {
                      const active = filters.sort === column.key
                      const Icon = !active ? ArrowUpDown : filters.dir === 'asc' ? ArrowUp : ArrowDown

                      return (
                        <th
                          key={column.key}
                          aria-sort={active ? (filters.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                          className={cn('px-4 py-3', column.align === 'right' && 'text-right')}
                        >
                          <button
                            type="button"
                            onClick={() => sortBy(column.key)}
                            className={cn(
                              'inline-flex items-center gap-1.5 hover:text-white',
                              column.align === 'right' && 'flex-row-reverse',
                            )}
                          >
                            {column.label}
                            <Icon size={13} aria-hidden className={active ? 'text-brand' : 'text-white/50'} />
                          </button>
                        </th>
                      )
                    })}
                    <th className="w-12 px-2 py-3">
                      <span className="sr-only">Actions</span>
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-hairline text-white/90">
                  {items.map((row) => (
                    <tr key={row.id} className={cn('hover:bg-white/4', row.archived && 'opacity-60')}>
                      <td className="px-4 py-2.5">{row.category}</td>
                      <td className="px-4 py-2.5 font-medium text-white">{row.itemCode}</td>
                      <td className="px-4 py-2.5">{row.description}</td>
                      <td className="px-4 py-2.5">{row.unit}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums">{money(row.materialPrice)}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums">{money(row.laborHours)}</td>
                      <td className="px-2 py-2.5 text-center">
                        <MoreMenu
                          variant="minimal"
                          ariaLabel={`Actions for ${row.itemCode}`}
                          items={[
                            { label: 'Edit', icon: Pencil, onSelect: () => setEditing(row) },
                            row.archived
                              ? {
                                  label: 'Restore',
                                  icon: RotateCcw,
                                  onSelect: () =>
                                    router.post(
                                      routeTo.commodityArchive(row.id),
                                      { restore: true },
                                      { preserveScroll: true },
                                    ),
                                }
                              : {
                                  label: 'Archive',
                                  icon: Archive,
                                  destructive: true,
                                  onSelect: () =>
                                    router.post(routeTo.commodityArchive(row.id), {}, { preserveScroll: true }),
                                },
                          ]}
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {page.total > 0 && (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-white/85">
                Showing {page.from}-{page.to} of {page.total} {page.total === 1 ? 'item' : 'items'}
              </p>
              {page.last > 1 && (
                <Pagination
                  tone="light"
                  withLabels
                  page={page.current}
                  pageCount={page.last}
                  onPageChange={(next) => visit({ page: next })}
                />
              )}
            </div>
          )}
        </div>
      </Card>

      {editing !== null && (
        <ItemModal
          key={editing === 'new' ? 'new' : editing.id}
          item={editing === 'new' ? null : editing}
          categories={categories}
          onClose={() => setEditing(null)}
        />
      )}
      {importing && (
        <ImportModal
          formats={formats}
          error={(errors as Record<string, string | undefined>)['file']}
          onClose={() => setImporting(false)}
        />
      )}
    </PageTransition>
  )
}

CommodityList.layout = appLayout

function ItemModal({
  item,
  categories,
  onClose,
}: {
  item: CommodityRow | null
  categories: readonly string[]
  onClose: () => void
}) {
  const { errors } = usePage<SharedPageProps>().props
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState<FormState>(
    item === null
      ? BLANK
      : {
          category: item.category,
          item_code: item.itemCode,
          description: item.description,
          unit: item.unit,
          material_price: String(item.materialPrice),
          labor_hours: String(item.laborHours),
          markup_pct: item.markupPct === null ? '' : String(item.markupPct),
        },
  )
  const set = (key: keyof FormState) => (event: React.ChangeEvent<HTMLInputElement>) =>
    setForm((current) => ({ ...current, [key]: event.target.value }))
  const error = (key: keyof FormState) => {
    const message = (errors as Record<string, string | undefined>)[key]

    return message ? { error: message } : {}
  }

  const submit = () => {
    const options = {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onSuccess: onClose,
    }
    if (item === null) router.post(ROUTES.commodities, form, options)
    else router.put(routeTo.commodityUpdate(item.id), form, options)
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={item === null ? 'Add item' : 'Edit item'}
      description="Material price is per unit, and labor hours are the hours one unit takes to install."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button isLoading={busy} onClick={submit}>
            {item === null ? 'Add item' : 'Save changes'}
          </Button>
        </>
      }
    >
      <form
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(event) => {
          event.preventDefault()
          submit()
        }}
      >
        <div>
          <TextInput
            id="commodity-category"
            label="Category"
            list="commodity-categories"
            maxLength={80}
            autoFocus
            value={form.category}
            onChange={set('category')}
            {...error('category')}
          />
          <datalist id="commodity-categories">
            {categories.map((name) => (
              <option key={name} value={name} />
            ))}
          </datalist>
        </div>
        <TextInput
          id="commodity-code"
          label="Item code (optional)"
          maxLength={40}
          placeholder="ELE-001"
          value={form.item_code}
          onChange={set('item_code')}
          {...error('item_code')}
        />
        <TextInput
          id="commodity-description"
          label="Description"
          maxLength={255}
          className="sm:col-span-2"
          value={form.description}
          onChange={set('description')}
          {...error('description')}
        />
        <TextInput
          id="commodity-unit"
          label="Unit"
          maxLength={16}
          placeholder="EA, LF, SF, HR"
          value={form.unit}
          onChange={set('unit')}
          {...error('unit')}
        />
        <TextInput
          id="commodity-price"
          label="Material price ($)"
          inputMode="decimal"
          placeholder="0.00"
          value={form.material_price}
          onChange={set('material_price')}
          {...error('material_price')}
        />
        <TextInput
          id="commodity-hours"
          label="Labor hours"
          inputMode="decimal"
          placeholder="0.00"
          value={form.labor_hours}
          onChange={set('labor_hours')}
          {...error('labor_hours')}
        />
        <TextInput
          id="commodity-markup"
          label="Markup % (optional)"
          inputMode="decimal"
          placeholder="0"
          value={form.markup_pct}
          onChange={set('markup_pct')}
          {...error('markup_pct')}
        />
      </form>
    </Modal>
  )
}

function ImportModal({
  formats,
  error,
  onClose,
}: {
  formats: readonly string[]
  error: string | undefined
  onClose: () => void
}) {
  const input = useRef<HTMLInputElement>(null)
  const [files, setFiles] = useState<File[]>([])
  const [busy, setBusy] = useState(false)
  const { errors } = usePage<SharedPageProps>().props
  // One message per file, so each one that could not be read says why.
  const problems = Object.entries(errors as Record<string, string>)
    .filter(([key]) => key.startsWith('file'))
    .map(([, message]) => message)
  const lines = problems.length > 0 ? problems : error ? [error] : []

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Upload your price list"
      description="Upload the list you already have, in any format. We read the items, units, prices and hours from it and add them to your list."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            leftIcon={Upload}
            isLoading={busy}
            disabled={files.length === 0}
            onClick={() =>
              router.post(
                routeTo.commodityImport,
                { files },
                {
                  forceFormData: true,
                  preserveScroll: true,
                  onStart: () => setBusy(true),
                  onFinish: () => setBusy(false),
                  onSuccess: onClose,
                },
              )
            }
          >
            Read and add items
          </Button>
        </>
      }
    >
      <div className="space-y-4 text-sm text-white/85">
        <p>
          Excel, CSV, PDF or Word ({formats.join(' ')}). Each item needs a name and a price or hours; headings like
          Description, Unit, Price and Hours help us find them. An item already on your list is updated, not added
          twice.
        </p>
        <a
          href={routeTo.commodityTemplate}
          className="inline-flex items-center gap-2 font-semibold text-brand hover:underline"
        >
          <Download size={15} aria-hidden /> Download a template
        </a>
        <div>
          <input
            ref={input}
            type="file"
            multiple
            accept={formats.join(',')}
            aria-label="Price list files"
            className="sr-only"
            onChange={(event) => setFiles(Array.from(event.target.files ?? []))}
          />
          <Button type="button" variant="outline" leftIcon={Upload} onClick={() => input.current?.click()}>
            {files.length === 0
              ? 'Choose files'
              : files.length === 1
                ? (files[0]?.name ?? '1 file')
                : `${files.length} files chosen`}
          </Button>
        </div>
        {lines.length > 0 && (
          <Alert tone="danger">
            <ul className="space-y-1">
              {lines.map((line) => (
                <li key={line}>{line}</li>
              ))}
            </ul>
          </Alert>
        )}
      </div>
    </Modal>
  )
}
