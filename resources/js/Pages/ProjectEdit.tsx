import { useRef } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, DollarSign, FileText, Layers, MapPin, Save, X } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  IconButton,
  SelectField,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { ClientOption } from '@/types'
import { cleanAmountInput, formatAmountInput, formatFileSize } from '@/utils'

interface ProjectEditDraft {
  client_id: string
  name: string
  estimate_target_total: string
  vendor_rate_list: File[]
}

export interface ProjectEditProps {
  project: {
    id: number
    name: string
    clientId: number | null
    estimateTargetTotal: number | null
  }
  /** The client register, each with the sites it has on file. */
  clients: readonly ClientOption[]
}

/**
 * Edit Project.
 *
 * The same screen as Add Project, filled in with what is already on record —
 * the same fields, the same rules, so correcting a project is never a
 * different form from opening one.
 */
export default function ProjectEdit({ project, clients }: ProjectEditProps) {
  const fileInputRef = useRef<HTMLInputElement>(null)

  const { data, setData, put, processing, errors, hasErrors, clearErrors } =
    useForm<ProjectEditDraft>({
      client_id: project.clientId === null ? '' : String(project.clientId),
      name: project.name,
      estimate_target_total:
        project.estimateTargetTotal === null ? '' : String(project.estimateTargetTotal),
      vendor_rate_list: [],
    })

  /**
   * Inertia keeps server errors until the next request, which would leave
   * "Project name is required" sitting under a field just filled in, so each
   * edit clears its own message.
   */
  const update = <K extends FormDataKeys<ProjectEditDraft>>(
    field: K,
    value: FormDataValues<ProjectEditDraft, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const selectClient = (clientId: string) => update('client_id', clientId)

  const selectedClient = clients.find((option) => String(option.id) === data.client_id)
  const primarySite = selectedClient?.addresses.find((site) => site.isPrimary)

  const clientOptions = [
    { label: 'Select client', value: '' },
    ...clients.map((client) => ({ label: client.name, value: String(client.id) })),
  ]

  const vendorRateListErrors = Object.entries(errors)
    .filter(([key]) => key === 'vendor_rate_list' || key.startsWith('vendor_rate_list.'))
    .map(([, message]) => message)

  const clearRateListErrors = () => {
    const keys = Object.keys(errors).filter(
      (key) => key === 'vendor_rate_list' || key.startsWith('vendor_rate_list.'),
    )
    if (keys.length > 0) clearErrors(...(keys as FormDataKeys<ProjectEditDraft>[]))
  }

  /** Each "Choose files" click adds to the list — it does not replace it. */
  const chooseRateLists = (fileList: FileList | null) => {
    if (!fileList || fileList.length === 0) return

    const incoming = Array.from(fileList)
    const isDuplicate = (a: File, b: File) =>
      a.name === b.name && a.size === b.size && a.lastModified === b.lastModified

    setData('vendor_rate_list', [
      ...data.vendor_rate_list,
      ...incoming.filter((file) => !data.vendor_rate_list.some((existing) => isDuplicate(existing, file))),
    ])
    clearRateListErrors()
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const removeRateList = (index: number) => {
    setData('vendor_rate_list', data.vendor_rate_list.filter((_, i) => i !== index))
    clearRateListErrors()
  }

  const rateListTotalSize = data.vendor_rate_list.reduce((total, file) => total + file.size, 0)

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    put(routeTo.project(project.id), { forceFormData: true })
  }

  return (
    <PageTransition>
      <Head title={`Edit ${project.name}`} />

      <PageHeader
        title={`Edit ${project.name}`}
        breadcrumbs={[
          { label: 'Projects', href: ROUTES.projects },
          { label: project.name, href: routeTo.project(project.id) },
          { label: 'Edit' },
        ]}
        actions={
          <ButtonLink href={routeTo.project(project.id)} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      <form onSubmit={submit} noValidate className="space-y-6">
        <AnimatePresence initial={false}>
          {hasErrors && (
            <Alert key="form-error" tone="danger" title="Check the form">
              Some fields need attention before this project can be saved.
            </Alert>
          )}
        </AnimatePresence>

        <Card padding="lg">
          <CardHeader title="Project details" />

          <div className="space-y-6">
            <SelectField
              id="project-client"
              label="Client*"
              options={clientOptions}
              value={data.client_id}
              onChange={(event) => selectClient(event.target.value)}
              {...(errors.client_id ? { error: errors.client_id } : {})}
            />

            <TextInput
              id="project-name"
              label="Project Name*"
              placeholder="e.g. Harborview Phase 2"
              autoComplete="off"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
            />

            <TextInput
              id="project-estimate-target-total"
              type="text"
              inputMode="decimal"
              leftIcon={DollarSign}
              label="Estimate Project Cost (Optional)"
              placeholder="e.g. 24,850"
              value={formatAmountInput(data.estimate_target_total)}
              onChange={(event) => update('estimate_target_total', cleanAmountInput(event.target.value))}
              {...(errors.estimate_target_total ? { error: errors.estimate_target_total } : {})}
            />

            <div>
              <label
                htmlFor="project-vendor-rate-list"
                className="mb-2 block text-md font-medium text-white"
              >
                Upload Vendor Rate List — Excel, PDF or Word (Optional)
              </label>

              <input
                ref={fileInputRef}
                id="project-vendor-rate-list"
                type="file"
                accept=".xlsx,.pdf,.doc,.docx,.rtf,.odt"
                multiple
                onChange={(event) => chooseRateLists(event.target.files)}
                className="sr-only"
              />

              <Button
                type="button"
                variant="secondary"
                leftIcon={FileText}
                onClick={() => fileInputRef.current?.click()}
              >
                Choose files
              </Button>

              {data.vendor_rate_list.length > 0 && (
                <div className="mt-3 rounded-panel border border-hairline bg-white/4 p-3">
                  <div className="mb-2 flex items-center justify-between gap-3">
                    <p className="flex items-center gap-2 text-sm font-medium text-white">
                      <Layers size={14} aria-hidden className="text-brand" />
                      {data.vendor_rate_list.length} file
                      {data.vendor_rate_list.length === 1 ? '' : 's'} selected
                    </p>
                    <p className="text-2xs text-white/70">{formatFileSize(rateListTotalSize)}</p>
                  </div>

                  <ul className="space-y-1.5">
                    {data.vendor_rate_list.map((file, index) => (
                      <li
                        key={`${file.name}-${file.size}-${file.lastModified}`}
                        className="flex min-w-0 items-center gap-2 rounded-panel border border-hairline bg-navy-950/35 px-3 py-1.5"
                      >
                        <span className="min-w-0 flex-1 truncate text-sm text-white/85">
                          {file.name}
                        </span>
                        <span className="shrink-0 text-2xs text-white/60">
                          {formatFileSize(file.size)}
                        </span>
                        <IconButton
                          icon={X}
                          label={`Remove ${file.name}`}
                          size="sm"
                          onClick={() => removeRateList(index)}
                        />
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {vendorRateListErrors.length > 0 ? (
                <div className="mt-2 space-y-1">
                  {vendorRateListErrors.map((message, index) => (
                    <p key={index} className="text-sm text-red-300">
                      {message}
                    </p>
                  ))}
                </div>
              ) : (
                <p className="mt-2 text-sm text-white/75">
                  Excel, PDF, or Word — however the vendor sent it. Anything chosen
                  here is added to this project's own rate list, alongside whatever
                  it already has.
                </p>
              )}
            </div>

            {/*
              Told, not asked. A project and the job on it are at the same
              place, and that place is already in the client's book — so it is
              shown here rather than entered a second time.
            */}
            {selectedClient && (
              <div className="flex items-start gap-3 rounded-panel border border-hairline bg-white/4 p-4">
                <MapPin size={16} aria-hidden className="mt-0.5 shrink-0 text-white/60" />
                <span className="min-w-0">
                  <span className="block text-2xs tracking-wide text-white/70 uppercase">
                    Site location
                  </span>
                  <span className="mt-0.5 block truncate text-md text-white">
                    {primarySite?.display ?? 'No site on this client yet'}
                  </span>
                  {!primarySite && (
                    <span className="mt-1 block text-sm text-white/60">
                      One is added the first time a job needs it.
                    </span>
                  )}
                </span>
              </div>
            )}
          </div>
        </Card>

        <div className="flex flex-wrap items-center justify-end gap-3">
          <ButtonLink href={routeTo.project(project.id)} variant="white">
            Cancel
          </ButtonLink>
          <Button type="submit" leftIcon={Save} isLoading={processing}>
            Save Changes
          </Button>
        </div>
      </form>
    </PageTransition>
  )
}

ProjectEdit.layout = appLayout
