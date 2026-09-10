import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, FolderKanban, MapPin } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  SelectField,
  TextInput,
  UnfinishedTakeoffNotice,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { ClientOption, ResumableTakeoff } from '@/types'

interface ProjectDraft {
  client_id: string
  name: string
  estimate_target_total: string
}

export interface ProjectCreateProps {
  /** The client register, each with the sites it has on file. */
  clients: readonly ClientOption[]
  /** Set when the form was opened from a client's own screen. */
  defaultClientId: number | null
  unfinishedTakeoff: ResumableTakeoff | null
}

/**
 * Add Project.
 *
 * A project is a piece of work for a client — what a drawing is taken off, and
 * what every takeoff, estimate and job is raised against. No drawings arrive
 * here: a PDF is uploaded from AI Takeoff against a project that exists, so
 * the product has one upload path rather than three.
 */
export default function ProjectCreate({
  clients,
  defaultClientId,
  unfinishedTakeoff,
}: ProjectCreateProps) {
  const { data, setData, post, processing, errors, hasErrors, clearErrors } =
    useForm<ProjectDraft>({
      client_id: defaultClientId === null ? '' : String(defaultClientId),
      name: '',
      estimate_target_total: '',
    })

  /**
   * Inertia keeps server errors until the next request, which would leave
   * "Project name is required" sitting under a field just filled in, so each
   * edit clears its own message.
   */
  const update = <K extends FormDataKeys<ProjectDraft>>(
    field: K,
    value: FormDataValues<ProjectDraft, K>,
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

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    post(ROUTES.projects)
  }

  return (
    <PageTransition>
      <Head title="Add Project" />

      <PageHeader
        title="Add Project"
        breadcrumbs={[
          { label: 'Projects', href: ROUTES.projects },
          { label: 'New Project' },
        ]}
        actions={
          <ButtonLink
            href={
              defaultClientId === null
                ? ROUTES.projects
                : routeTo.client(defaultClientId)
            }
            variant="secondary"
            leftIcon={ArrowLeft}
          >
            Back
          </ButtonLink>
        }
      />

      <form onSubmit={submit} noValidate className="space-y-6">
        <AnimatePresence initial={false}>
          {hasErrors && (
            <Alert key="form-error" tone="danger" title="Check the form">
              Some fields need attention before this project can be opened.
            </Alert>
          )}
        </AnimatePresence>

        <UnfinishedTakeoffNotice takeoff={unfinishedTakeoff} starting="another project" />

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
              type="number"
              inputMode="decimal"
              min={0}
              step={50}
              label="Estimate Project Cost (Optional)"
              placeholder="e.g. 24850"
              value={data.estimate_target_total}
              onChange={(event) => update('estimate_target_total', event.target.value)}
              {...(errors.estimate_target_total ? { error: errors.estimate_target_total } : {})}
            />

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
          <ButtonLink href={ROUTES.projects} variant="white">
            Cancel
          </ButtonLink>
          <Button type="submit" leftIcon={FolderKanban} isLoading={processing}>
            Add Project
          </Button>
        </div>
      </form>
    </PageTransition>
  )
}

ProjectCreate.layout = appLayout
