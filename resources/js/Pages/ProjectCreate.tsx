import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, FolderKanban } from 'lucide-react'
import {
  AddressListField,
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  RadioGroup,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { PROJECT_TYPE_OPTIONS, ROUTES } from '@/constants'
import type { ProjectDraft, ProjectType } from '@/types'
import { emptyAddress } from '@/utils'

export interface ProjectCreateProps {
  /** Clients already on record, offered as suggestions on the name field. */
  clients: readonly string[]
}

/**
 * Create New Client.
 *
 * The client's own details and nothing else — no drawings are attached here. A
 * PDF is uploaded from AI Takeoff, against a client that already exists, so the
 * product has one upload path rather than three.
 */
export default function ProjectCreate({ clients }: ProjectCreateProps) {
  const { data, setData, post, transform, processing, errors, hasErrors, clearErrors } =
    useForm<ProjectDraft>({
      name: '',
      code: '',
      addresses: [emptyAddress()],
      project_type: '',
      due_date: '',
      notes: '',
    })

  /**
   * Inertia keeps server errors until the next request, which would leave
   * "Client name is required" sitting under a field the user has just filled
   * in, so each edit clears its own message.
   */
  const update = <K extends FormDataKeys<ProjectDraft>>(
    field: K,
    value: FormDataValues<ProjectDraft, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    /*
     * The form always shows one empty row to type into, and a client can be
     * opened before any site is known — so a row nobody filled in is dropped
     * rather than rejected as a missing address.
     */
    transform((payload) => ({
      ...payload,
      addresses: payload.addresses.filter((row) => row.address.trim() !== ''),
    }))

    post(ROUTES.projects)
  }

  return (
    <PageTransition>
      <Head title="Create New Client" />

      <PageHeader
        title="Create New Client"
        subtitle="Record the client, then run their drawings through AI Takeoff."
        breadcrumbs={[
          { label: 'Clients', href: ROUTES.projects },
          { label: 'New Client' },
        ]}
        actions={
          <ButtonLink href={ROUTES.projects} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      <form onSubmit={submit} noValidate className="space-y-6">
        <AnimatePresence initial={false}>
          {hasErrors && (
            <Alert key="form-error" tone="danger" title="Check the form">
              Some fields need attention before this client can be created.
            </Alert>
          )}
        </AnimatePresence>

        <Card padding="lg">
          <CardHeader
            title="Client details"
            subtitle="Who the client is, and where the work is"
          />

          <div className="space-y-6">
            {/*
              One field rather than a separate name + client pair: clients
              already on record are offered as suggestions via the datalist,
              and a client new to the workspace is typed straight in.
            */}
            <TextInput
              id="project-name"
              label="Client Name*"
              placeholder="e.g. Harborview Data Hall"
              list="project-known-clients"
              autoComplete="off"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(clients.length > 0
                ? { hint: 'Existing clients are suggested as you type.' }
                : {})}
              {...(errors.name ? { error: errors.name } : {})}
            />
            <datalist id="project-known-clients">
              {clients.map((client) => (
                <option key={client} value={client} />
              ))}
            </datalist>

            <TextInput
              id="project-due"
              type="date"
              label="Takeoff Due"
              className="lg:max-w-xs"
              value={data.due_date}
              onChange={(event) => update('due_date', event.target.value)}
              {...(errors.due_date ? { error: errors.due_date } : {})}
            />

            <RadioGroup
              name="project-type"
              label="Client Type"
              options={PROJECT_TYPE_OPTIONS}
              value={data.project_type}
              onChange={(value) => update('project_type', value as ProjectType)}
              {...(errors.project_type ? { error: errors.project_type } : {})}
            />

            <div className="border-t border-hairline pt-6">
              <p className="text-md font-medium text-white">Sites</p>
              <p className="mt-1 mb-4 text-sm text-white/75">
                Every address this client has work at. The first is the primary —
                it names the client in lists, and a job starts on it.
              </p>

              <AddressListField
                addresses={data.addresses}
                onChange={(addresses) => setData('addresses', addresses)}
                errors={errors as Record<string, string>}
                disabled={processing}
              />
            </div>

            <TextArea
              id="project-notes"
              label="Notes for the takeoff"
              rows={4}
              maxLength={2000}
              placeholder="Anything the estimator should know before counting — revisions, exclusions, scope splits."
              value={data.notes}
              onChange={(event) => update('notes', event.target.value)}
              addon={`${data.notes.length}/2000`}
              {...(errors.notes ? { error: errors.notes } : {})}
            />
          </div>
        </Card>

        <div className="flex flex-wrap items-center justify-end gap-3">
          <ButtonLink href={ROUTES.projects} variant="white">
            Cancel
          </ButtonLink>
          <Button type="submit" leftIcon={FolderKanban} isLoading={processing}>
            Create Client
          </Button>
        </div>
      </form>
    </PageTransition>
  )
}

ProjectCreate.layout = appLayout
