import { useRef, useState } from 'react'
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
  RequiredFieldsNotice,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { ProjectMemberPicker, TEAM_DRAFT_PREFIX, TeamPicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { PROJECT_TYPE_OPTIONS, ROUTES, routeTo } from '@/constants'
import type { ClientOption, ClientTeamMemberOption } from '@/types'
import { cleanAmountInput, formatAmountInput, formatFileSize } from '@/utils'

interface ProjectEditDraft {
  client_id: string
  name: string
  description: string
  project_type: string
  start_date: string
  end_date: string
  estimate_target_total: string
  team_id: string
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
  /** The crew register, for the Team field — same picker Edit Client offers. */
  teams: readonly { readonly id: number; readonly name: string }[]
}

/**
 * Edit Project.
 *
 * The same screen as Add Project, filled in with what is already on record —
 * the same fields, the same rules, so correcting a project is never a
 * different form from opening one.
 */
export default function ProjectEdit({ project, clients, teams }: ProjectEditProps) {
  const fileInputRef = useRef<HTMLInputElement>(null)

  const initialClient = clients.find((option) => option.id === project.clientId)
  const alreadyStaffedIds = project.memberIds.map(String)

  /*
   * Who is actually on record as staffed to this project — unlike
   * `data.member_ids`, which also includes anyone just defaulted or hand-
   * checked in this still-unsaved form. Reset to empty on a client switch:
   * nothing staffed under the old client means anything for the new one.
   */
  const [assignedIds, setAssignedIds] = useState<readonly string[]>(alreadyStaffedIds)

  const { data, setData, post, transform, processing, errors, hasErrors, clearErrors } =
    useForm<ProjectEditDraft>({
      client_id: project.clientId === null ? '' : String(project.clientId),
      name: project.name,
      description: project.description ?? '',
      project_type: project.projectType ?? '',
      start_date: project.startDate ?? '',
      end_date: project.endDate ?? '',
      estimate_target_total:
        project.estimateTargetTotal === null ? '' : String(project.estimateTargetTotal),
      team_id: initialClient?.teamId == null ? '' : String(initialClient.teamId),
      /*
       * Already-staffed members, plus the client's own crew defaulted in on
       * top — unchecking one who shouldn't be on this particular project is
       * the exception.
       */
      member_ids: Array.from(
        new Set([...alreadyStaffedIds, ...(initialClient?.members ?? []).map((member) => String(member.id))]),
      ),
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

    // Nothing staffed under the previous client means anything here.
    setAssignedIds([])

    setData((current) => ({
      ...current,
      client_id: clientId,
      // The new client's own crew, not whichever one the last client had.
      member_ids: (client?.members ?? []).map((member) => String(member.id)),
      // The new client's own team, not whichever one the last client had.
      team_id: client?.teamId == null ? '' : String(client.teamId),
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

  /*
   * The member list only knows the client's own team's roster as it was when
   * the page loaded — picking a different team here (or drafting a new one)
   * has no roster to show yet, so the picker falls back to "no one yet" and
   * an inline add starts that team's roster rather than showing someone
   * else's.
   */
  const clientTeamIdValue = selectedClient?.teamId == null ? '' : String(selectedClient.teamId)
  const teamUnchangedFromClient = data.team_id === clientTeamIdValue
  const isDraftTeam = data.team_id.startsWith(TEAM_DRAFT_PREFIX)
  const memberPickerTeamId = data.team_id === '' || isDraftTeam ? null : Number(data.team_id)

  /*
   * Who can be staffed to this project: the client's own people (picked on
   * Add Client, independent of any team) plus — when the team here still
   * matches the client's own — that team's roster too. Either one is a
   * legitimate way someone ended up on this client's crew.
   */
  const memberPickerMembers: ClientTeamMemberOption[] = []
  const seenMemberIds = new Set<number>()
  for (const member of [
    ...(selectedClient?.members ?? []),
    ...(teamUnchangedFromClient ? (selectedClient?.teamMembers ?? []) : []),
  ]) {
    if (seenMemberIds.has(member.id)) continue
    seenMemberIds.add(member.id)
    memberPickerMembers.push(member)
  }

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
    /*
     * Sent as a spoofed PUT, not a real one: PHP never parses a multipart
     * body on an actual PUT request, so every field — not just the file —
     * would arrive empty and "required" would fire on whatever has that
     * rule. Posting with `_method` is what every other multipart edit in
     * this app already does — see ChangeOrderForm's own `editing` branch.
     */
    transform((form) => ({ ...form, _method: 'put' }))
    post(routeTo.project(project.id), { forceFormData: true })
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
        <RequiredFieldsNotice fields={['Client', 'Project Name']} />
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
              Which crew normally works this client's sites — sets the
              client's own Team field, the same one Edit Client offers.
            */}
            {selectedClient && (
              <TeamPicker
                teams={teams}
                value={data.team_id}
                onChange={(next) => update('team_id', next)}
                hint="Who normally works this client's sites. Setting it here updates the client's own team."
                disabled={processing}
                {...(errors.team_id ? { error: errors.team_id } : {})}
              />
            )}

            {/*
              Who from the client's own crew works this project. Offered only
              once a client is picked — the roster is theirs, not a blank
              choice of everyone on the register.
            */}
            {selectedClient && (
              <ProjectMemberPicker
                teamId={memberPickerTeamId}
                teamName={teamUnchangedFromClient ? selectedClient.teamName : null}
                members={memberPickerMembers}
                value={data.member_ids}
                onChange={(ids) => update('member_ids', ids)}
                disabled={processing}
                assignedIds={assignedIds}
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
