import { Head, Link, router, usePage } from '@inertiajs/react'
import { FileText, Paperclip, Plus, Save, Send, Trash2, Upload, X } from 'lucide-react'
import { useRef, useState } from 'react'
import { Alert, Button, Card, SelectField, TextArea, TextInput } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { ChangeOrderLineKind, ChangeOrderSource, SharedPageProps } from '@/types'
import { cn, formatCurrency } from '@/utils'

interface JobOption {
  readonly id: number
  readonly name: string
  readonly client: string | null
  readonly laborRate: number | null
}

interface ExistingOrder {
  readonly id: number
  readonly label: string
  readonly description: string
  readonly reason: string | null
  readonly source: ChangeOrderSource
  readonly markupPct: number
  readonly job: { readonly id: number; readonly name: string }
  readonly lines: readonly {
    readonly kind: ChangeOrderLineKind
    readonly description: string
    readonly quantity: number
    readonly unit: string | null
    readonly unitCost: number
  }[]
  readonly attachments: readonly { readonly id: number; readonly name: string; readonly size: number }[]
  readonly rejection: string | null
}

export interface ChangeOrderFormProps {
  changeOrder: ExistingOrder | null
  jobs: readonly JobOption[]
  preselectedJob: number | null
  isManager: boolean
}

interface Line {
  readonly key: string
  description: string
  quantity: string
  unit: string
  unitCost: string
}

let counter = 0
const blank = (unit = ''): Line => ({ key: `line-${++counter}`, description: '', quantity: '', unit, unitCost: '' })
const isBlank = (line: Line) => line.description.trim() === '' && line.quantity === '' && line.unitCost === ''
const toNumber = (value: string) => (value.trim() === '' || Number.isNaN(Number(value)) ? 0 : Number(value))
const size = (bytes: number) =>
  bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`

/** Creates or edits a change order: what was added, as material and labor lines, with the evidence for it. */
export default function ChangeOrderForm({ changeOrder, jobs, preselectedJob, isManager }: ChangeOrderFormProps) {
  const { errors } = usePage<SharedPageProps>().props
  const fieldErrors = errors as Record<string, string | undefined>
  const editing = changeOrder !== null
  const fileInput = useRef<HTMLInputElement>(null)

  const linesOf = (kind: ChangeOrderLineKind, fallback: Line): Line[] => {
    const found = (changeOrder?.lines ?? []).filter((line) => line.kind === kind)

    return found.length === 0
      ? [fallback]
      : found.map((line) => ({
          ...blank(),
          description: line.description,
          quantity: String(line.quantity),
          unit: line.unit ?? '',
          unitCost: String(line.unitCost),
        }))
  }

  const [jobId, setJobId] = useState(
    changeOrder ? String(changeOrder.job.id) : preselectedJob ? String(preselectedJob) : '',
  )
  const [description, setDescription] = useState(changeOrder?.description ?? '')
  const [reason, setReason] = useState(changeOrder?.reason ?? '')
  const [source, setSource] = useState<ChangeOrderSource>(changeOrder?.source ?? 'office')
  const [markup, setMarkup] = useState(changeOrder ? String(changeOrder.markupPct) : '')
  const [materials, setMaterials] = useState<Line[]>(() => linesOf('material', blank('EA')))
  const [labor, setLabor] = useState<Line[]>(() => linesOf('labor', blank('HR')))
  const [files, setFiles] = useState<File[]>([])
  const [busy, setBusy] = useState(false)

  const job = jobs.find((option) => String(option.id) === jobId)

  // What is sent: the lines that were filled in, materials first — the same order errors come back in.
  const sent = [
    ...materials.filter((line) => !isBlank(line)).map((line) => ({ kind: 'material' as const, line })),
    ...labor.filter((line) => !isBlank(line)).map((line) => ({ kind: 'labor' as const, line })),
  ]
  const errorFor = (line: Line, field: string) => {
    const index = sent.findIndex((entry) => entry.line === line)

    return index === -1 ? undefined : fieldErrors[`lines.${index}.${field}`]
  }

  const total = (list: Line[]) => list.reduce((sum, line) => sum + toNumber(line.quantity) * toNumber(line.unitCost), 0)
  const laborHours = labor.reduce((sum, line) => sum + toNumber(line.quantity), 0)
  const materialCost = total(materials)
  const laborCost = total(labor)
  const cost = materialCost + laborCost
  const sell = cost * (1 + toNumber(markup) / 100)

  const update = (setter: React.Dispatch<React.SetStateAction<Line[]>>, key: string, changes: Partial<Line>) =>
    setter((lines) => lines.map((line) => (line.key === key ? { ...line, ...changes } : line)))

  const pickJob = (value: string) => {
    setJobId(value)
    const rate = jobs.find((option) => String(option.id) === value)?.laborRate
    // A labor line not yet touched starts at what this job's client is billed for labor.
    if (rate != null)
      setLabor((lines) => lines.map((line) => (isBlank(line) ? { ...line, unitCost: String(rate) } : line)))
  }

  const submit = (andSubmit: boolean) => {
    const data = {
      ...(editing ? { _method: 'put' } : { job_id: jobId }),
      description,
      reason,
      ...(isManager ? { source } : {}),
      markup_pct: markup === '' ? 0 : markup,
      lines: sent.map(({ kind, line }) => ({
        kind,
        description: line.description,
        quantity: line.quantity,
        unit: line.unit,
        unit_cost: line.unitCost === '' ? 0 : line.unitCost,
      })),
      attachments: files,
      ...(andSubmit ? { submit: 1 } : {}),
    }

    router.post(editing ? routeTo.changeOrder(changeOrder.id) : ROUTES.changeOrders, data, {
      forceFormData: true,
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
    })
  }

  const section = (
    title: string,
    kind: ChangeOrderLineKind,
    lines: Line[],
    setter: React.Dispatch<React.SetStateAction<Line[]>>,
  ) => {
    const isLabor = kind === 'labor'

    return (
      <Card padding="md" className="mt-4">
        <div className="mb-3 flex items-center justify-between gap-3">
          <h2 className="text-lg font-semibold text-white">{title}</h2>
          <span className="text-sm text-white/80 tabular-nums">
            {formatCurrency(isLabor ? laborCost : materialCost, 2)}
          </span>
        </div>

        <div className="hidden gap-3 px-1 pb-1 text-xs font-semibold text-white/75 sm:grid sm:grid-cols-[1fr_6rem_5rem_7rem_7rem_2rem]">
          <span>Description</span>
          <span>{isLabor ? 'Hours' : 'Quantity'}</span>
          <span>Unit</span>
          <span>{isLabor ? 'Rate ($/hr)' : 'Unit cost ($)'}</span>
          <span className="text-right">Line total</span>
          <span />
        </div>

        <div className="space-y-3 sm:space-y-2">
          {lines.map((line) => (
            <div key={line.key} className="grid items-start gap-2 sm:grid-cols-[1fr_6rem_5rem_7rem_7rem_2rem] sm:gap-3">
              <div>
                <TextInput
                  aria-label={`${title} description`}
                  placeholder={isLabor ? 'e.g. Journeyman install' : 'e.g. #6 copper wire'}
                  maxLength={255}
                  value={line.description}
                  onChange={(event) => update(setter, line.key, { description: event.target.value })}
                  {...(errorFor(line, 'description') ? { error: errorFor(line, 'description') as string } : {})}
                />
              </div>
              <TextInput
                aria-label={isLabor ? 'Hours' : 'Quantity'}
                inputMode="decimal"
                placeholder="0"
                value={line.quantity}
                onChange={(event) => update(setter, line.key, { quantity: event.target.value })}
                {...(errorFor(line, 'quantity') ? { error: errorFor(line, 'quantity') as string } : {})}
              />
              <TextInput
                aria-label="Unit"
                maxLength={16}
                value={line.unit}
                onChange={(event) => update(setter, line.key, { unit: event.target.value })}
              />
              <TextInput
                aria-label={isLabor ? 'Rate per hour' : 'Unit cost'}
                inputMode="decimal"
                placeholder="0.00"
                value={line.unitCost}
                onChange={(event) => update(setter, line.key, { unitCost: event.target.value })}
                {...(errorFor(line, 'unit_cost') ? { error: errorFor(line, 'unit_cost') as string } : {})}
              />
              <p className="pt-2.5 text-right text-sm text-white/90 tabular-nums">
                {formatCurrency(toNumber(line.quantity) * toNumber(line.unitCost), 2)}
              </p>
              <button
                type="button"
                aria-label="Remove this line"
                onClick={() =>
                  setter((current) =>
                    current.length === 1
                      ? [blank(isLabor ? 'HR' : 'EA')]
                      : current.filter((entry) => entry.key !== line.key),
                  )
                }
                className="mt-2 grid size-7 place-items-center rounded-full text-white/70 hover:bg-white/10 hover:text-white"
              >
                <Trash2 size={15} aria-hidden />
              </button>
            </div>
          ))}
        </div>

        <Button
          type="button"
          variant="outline"
          size="sm"
          leftIcon={Plus}
          className="mt-3"
          onClick={() => setter((current) => [...current, blank(isLabor ? 'HR' : 'EA')])}
        >
          Add {isLabor ? 'labor' : 'material'} line
        </Button>
      </Card>
    )
  }

  return (
    <PageTransition>
      <Head title={editing ? `Edit ${changeOrder.label}` : 'Create Change Order'} />

      <PageHeader
        title={editing ? `Edit ${changeOrder.label}` : 'Create Change Order'}
        subtitle="Document work, materials or labor added after the job began. No drawing is needed."
        breadcrumbs={[
          { label: 'Change Orders', href: ROUTES.changeOrders },
          { label: editing ? changeOrder.label : 'Create' },
        ]}
        className="mb-5"
      />

      {editing && changeOrder.rejection && (
        <Alert tone="warning" title="This change order was rejected" className="mb-4">
          {changeOrder.rejection}
        </Alert>
      )}
      {fieldErrors['lines'] && (
        <Alert tone="danger" className="mb-4">
          {fieldErrors['lines']}
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <div>
          <Card padding="md">
            <div className="grid gap-4 sm:grid-cols-2">
              {editing ? (
                <TextInput id="co-job" label="Job" disabled value={changeOrder.job.name} />
              ) : (
                <SelectField
                  id="co-job"
                  label="Job"
                  value={jobId}
                  onChange={(event) => pickJob(event.target.value)}
                  options={[
                    { value: '', label: jobs.length === 0 ? 'No job to add work to' : 'Select a job' },
                    ...jobs.map((option) => ({ value: String(option.id), label: option.name })),
                  ]}
                  {...(fieldErrors['job_id'] ? { error: fieldErrors['job_id'] } : {})}
                />
              )}
              {isManager ? (
                <SelectField
                  id="co-source"
                  label="Source"
                  value={source}
                  onChange={(event) => setSource(event.target.value as ChangeOrderSource)}
                  options={[
                    { value: 'office', label: 'Office' },
                    { value: 'field', label: 'Field' },
                  ]}
                />
              ) : (
                <TextInput id="co-source" label="Source" disabled value="Field" />
              )}
              <TextInput
                id="co-description"
                label="Description"
                className="sm:col-span-2"
                maxLength={255}
                placeholder="e.g. Add dedicated circuit for MRI suite"
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                {...(fieldErrors['description'] ? { error: fieldErrors['description'] } : {})}
              />
              <TextArea
                id="co-reason"
                label="Reason"
                className="sm:col-span-2"
                rows={3}
                maxLength={2000}
                placeholder="Why is this needed? Who asked for it?"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
              />
            </div>
            {job?.client && <p className="mt-3 text-sm text-white/75">Client: {job.client}</p>}
          </Card>

          {section('Materials', 'material', materials, setMaterials)}
          {section('Labor', 'labor', labor, setLabor)}
        </div>

        <aside className="space-y-4 lg:sticky lg:top-4 lg:self-start">
          <Card padding="md">
            <h2 className="mb-3 text-lg font-semibold text-white">Cost and sell impact</h2>
            <dl className="space-y-2 text-sm">
              {(
                [
                  ['Labor', `${laborHours} hrs · ${formatCurrency(laborCost, 2)}`],
                  ['Materials', formatCurrency(materialCost, 2)],
                  ['Cost', formatCurrency(cost, 2)],
                ] as const
              ).map(([label, value]) => (
                <div key={label} className="flex justify-between gap-3">
                  <dt className="text-white/75">{label}</dt>
                  <dd className="text-white tabular-nums">{value}</dd>
                </div>
              ))}
            </dl>
            <TextInput
              id="co-markup"
              label="Markup %"
              className="mt-3"
              inputMode="decimal"
              placeholder="0"
              value={markup}
              onChange={(event) => setMarkup(event.target.value)}
              {...(fieldErrors['markup_pct'] ? { error: fieldErrors['markup_pct'] } : {})}
            />
            <div className="mt-4 flex items-center justify-between border-t border-hairline pt-3">
              <span className="font-semibold text-white">Sell amount</span>
              <span className="text-xl font-bold text-white tabular-nums">{formatCurrency(sell, 2)}</span>
            </div>
          </Card>

          <Card padding="md">
            <h2 className="mb-1 text-lg font-semibold text-white">Evidence</h2>
            <p className="mb-3 text-sm text-white/75">Photos, quotes or emails that show why this was needed.</p>

            {editing && changeOrder.attachments.length > 0 && (
              <ul className="mb-3 space-y-1.5">
                {changeOrder.attachments.map((file) => (
                  <li
                    key={file.id}
                    className="flex items-center gap-2 rounded-panel border border-hairline bg-white/4 px-3 py-1.5 text-sm"
                  >
                    <FileText size={14} aria-hidden className="shrink-0 text-brand" />
                    <a
                      href={routeTo.changeOrderFile(changeOrder.id, file.id)}
                      className="min-w-0 flex-1 truncate text-white hover:text-brand"
                    >
                      {file.name}
                    </a>
                    <span className="text-2xs text-white/60">{size(file.size)}</span>
                    <button
                      type="button"
                      aria-label={`Remove ${file.name}`}
                      onClick={() =>
                        router.delete(routeTo.changeOrderFile(changeOrder.id, file.id), { preserveScroll: true })
                      }
                      className="text-white/70 hover:text-white"
                    >
                      <X size={14} aria-hidden />
                    </button>
                  </li>
                ))}
              </ul>
            )}

            {files.length > 0 && (
              <ul className="mb-3 space-y-1.5">
                {files.map((file, index) => (
                  <li
                    key={`${file.name}-${file.size}-${file.lastModified}`}
                    className="flex items-center gap-2 rounded-panel border border-hairline bg-navy-950/35 px-3 py-1.5 text-sm"
                  >
                    <Paperclip size={14} aria-hidden className="shrink-0 text-brand" />
                    <span className="min-w-0 flex-1 truncate text-white/90">{file.name}</span>
                    <span className="text-2xs text-white/60">{size(file.size)}</span>
                    <button
                      type="button"
                      aria-label={`Remove ${file.name}`}
                      onClick={() => setFiles((current) => current.filter((_, i) => i !== index))}
                      className="text-white/70 hover:text-white"
                    >
                      <X size={14} aria-hidden />
                    </button>
                  </li>
                ))}
              </ul>
            )}

            <input
              ref={fileInput}
              type="file"
              multiple
              aria-label="Evidence files"
              className="sr-only"
              onChange={(event) => {
                setFiles((current) => [...current, ...Array.from(event.target.files ?? [])])
                event.target.value = ''
              }}
            />
            <Button type="button" variant="outline" leftIcon={Upload} onClick={() => fileInput.current?.click()}>
              Attach evidence
            </Button>
            {Object.entries(fieldErrors)
              .filter(([key]) => key.startsWith('attachments'))
              .map(([key, message]) => (
                <p key={key} className={cn('mt-2 text-sm text-red-300')}>
                  {message}
                </p>
              ))}
          </Card>

          <div className="flex flex-wrap gap-3">
            <Button leftIcon={Send} isLoading={busy} onClick={() => submit(true)}>
              Save &amp; submit
            </Button>
            <Button variant="secondary" leftIcon={Save} isLoading={busy} onClick={() => submit(false)}>
              Save draft
            </Button>
            <Link
              href={editing ? routeTo.changeOrder(changeOrder.id) : ROUTES.changeOrders}
              className="self-center text-sm text-white/80 hover:text-white"
            >
              Cancel
            </Link>
          </div>
        </aside>
      </div>
    </PageTransition>
  )
}

ChangeOrderForm.layout = appLayout
