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
  TextArea,
  TextInput,
} from '@/components/common'
import { ProjectMemberPicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { PROJECT_TYPE_OPTIONS, ROUTES, routeTo } from '@/constants'
import type { ClientOption } from '@/types'
import { cleanAmountInput, formatAmountInput, formatFileSize } from '@/utils'

interface ProjectEditDraft {
  client_id: string
  name: string
  description: string
  project_type: string
  start_date: string
  end_date: string
  estimate_target_total: string
  member_ids: string[]
  vendor_rate_list: File[]
}

export interface ProjectEditProps {
  project: {
    id: number
    name: string
    description: string | null
    clientId: number | null
    projectType: string | null
    startDate: string | null
    endDate: string | null
    estimateTargetTotal: number | null
    /** Who from the client's crew is already staffed to it. */
    memberIds: readonly number[]
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
      description: project.description ?? '',
      project_type: project.projectType ?? '',
      start_date: project.startDate ?? '',
      end_date: project.endDate ?? '',
      estimate_target_total:
        project.estimateTargetTotal === null ? '' : String(project.estimateTargetTotal),
      member_ids: project.memberIds.map(String),
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

  const selectClient = (clientId: string) => {
    const client = clients.find((option) => String(option.id) === clientId)
    const primary = client?.addresses.find((site) => site.isPrimary)

    setData((current) => ({
      ...current,
      client_id: clientId,
      member_ids: [],
      /*
       * The type comes from the site, because it is the building that
       * decides it — a client can own a house and a warehouse. Only a site
       * that has been answered for speaks; otherwise whatever was already
       * chosen stands.
       */
      project_type: primary?.siteType ?? current.project_type,
    }))
    if (errors.client_id) clearErrors('client_id')
  }

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
            <div className="grid gap-6 sm:grid-cols-2">
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
            </div>

            {/*
              Told, not asked. A project and the job on it are at the same
              place, and that place is already in the client's book — shown
              here rather than entered a second time, and never editable from
              this screen for the same reason.
            */}
            <TextInput
              id="project-address"
              label="Address"
              leftIcon={MapPin}
              disabled
              value={primarySite?.display ?? ''}
              placeholder={
                selectedClient
                  ? 'No site on this client yet — one is added the first time a job needs it'
                  : 'Select a client to see their address'
              }
            />

            <div className="grid gap-6 sm:grid-cols-3">
              <SelectField
                id="project-type"
                label="Project Type"
                disabled
                hint="Set by the client's address"
                options={[{ label: 'Select project type', value: '' }, ...PROJECT_TYPE_OPTIONS]}
                value={data.project_type}
                onChange={(event) => update('project_type', event.target.value)}
                {...(errors.project_type ? { error: errors.project_type } : {})}
              />

              <TextInput
                id="project-start-date"
                type="date"
                label="Start Date"
                value={data.start_date}
                onChange={(event) => update('start_date', event.target.value)}
                {...(errors.start_date ? { error: errors.start_date } : {})}
              />

              <TextInput
                id="project-end-date"
                type="date"
                label="End Date"
                value={data.end_date}
                onChange={(event) => update('end_date', event.target.value)}
                {...(errors.end_date ? { error: errors.end_date } : {})}
              />
            </div>

            <TextArea
              id="project-description"
              label="Description"
              rows={5}
              placeholder="Enter project description (optional)..."
              value={data.description}
              onChange={(event) => update('description', event.target.value)}
              {...(errors.description ? { error: errors.description } : {})}
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
                Upload Commodity — Excel, CSV, PDF or Word (Optional)
              </label>

              <input
                ref={fileInputRef}
                id="project-vendor-rate-list"
                type="file"
                accept=".xlsx,.ods,.csv,.txt,.pdf,.doc,.docx,.rtf,.odt"
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
                  Excel, CSV, PDF or Word, in whatever form you have it. Anything chosen here is added to this
                  project's own commodity list, alongside what it already has. Anything it does not list is priced
                  from your company's default commodity list.
                </p>
              )}
            </div>

            {/*
              Who from the client's own crew works this project. Offered only
              once a client is picked — the roster is theirs, not a blank
              choice of everyone on the register.
            */}
            {selectedClient && (
              <ProjectMemberPicker
                teamId={selectedClient.teamId}
                teamName={selectedClient.teamName}
                members={selectedClient.teamMembers}
                value={data.member_ids}
                onChange={(ids) => update('member_ids', ids)}
                disabled={processing}
              />
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
