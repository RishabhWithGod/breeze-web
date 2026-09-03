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
  ConfirmDialog,
  RadioGroup,
  TextArea,
  TextInput,
  UnfinishedTakeoffNotice,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { useDisclosure } from '@/hooks'
import { PROJECT_TYPE_OPTIONS, ROUTES } from '@/constants'
import type { ProjectDraft, ProjectType, ResumableTakeoff } from '@/types'
import { emptyAddress } from '@/utils'

export interface ProjectCreateProps {
  /**
   * A takeoff already part-way through, if there is one. Starting a second
   * client is normal; doing it by accident and losing track of the first is
   * not, so this screen says so before it happens.
   */
  unfinishedTakeoff: ResumableTakeoff | null
}

/**
 * Create New Client.
 *
 * The client's own details and nothing else — no drawings are attached here. A
 * PDF is uploaded from AI Takeoff, against a client that already exists, so the
 * product has one upload path rather than three.
 */
export default function ProjectCreate({ unfinishedTakeoff }: ProjectCreateProps) {
  const confirmNew = useDisclosure()

  const { data, setData, post, transform, processing, errors, hasErrors, clearErrors } =
    useForm<ProjectDraft>({
      name: '',
      code: '',
      addresses: [emptyAddress()],
      project_type: '',
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

  const create = () => {
    confirmNew.close()

    /*
     * The form always shows one empty row to type into, and a client can be
     * opened before any site is known — so a row nobody filled in is dropped
     * rather than rejected as a missing address.
     */
    transform((payload) => ({
      ...payload,
      // Only a row nobody touched at all. One with a name but no address —
      // or the other way round — is a half-filled row, and dropping it would
      // throw away what was typed instead of saying what is missing.
      addresses: payload.addresses.filter(
        (row) => row.label.trim() !== '' || row.address.trim() !== '',
      ),
    }))

    post(ROUTES.projects)
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    // Asked once, before anything is created. Saying yes moves the resume
    // button onto the client about to be made.
    if (unfinishedTakeoff) {
      confirmNew.open()

      return
    }

    create()
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

        <UnfinishedTakeoffNotice takeoff={unfinishedTakeoff} starting="a new client" />

        <Card padding="lg">
          <CardHeader title="Client details" />

          <div className="space-y-6">
            {/*
              A plain text box. It carried a datalist of the clients already on
              record, which put a dropdown arrow on a field that is not a
              choice — this screen exists to name a client that is not on the
              list yet.
            */}
            <TextInput
              id="project-name"
              label="Client Name*"
              placeholder="e.g. Harborview Data Hall"
              autoComplete="off"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
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
              <p className="mb-4 text-md font-medium text-white">Site Location(s)</p>

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

      <ConfirmDialog
        isOpen={confirmNew.isOpen}
        tone="brand"
        title="Start a new client?"
        description={`“${unfinishedTakeoff?.projectName ?? ''}” is still at ${unfinishedTakeoff?.stage ?? ''}. It stays exactly as it is, but the resume button will follow this new client from here.`}
        confirmLabel="Yes, create it"
        onConfirm={create}
        onCancel={confirmNew.close}
      />
    </PageTransition>
  )
}

ProjectCreate.layout = appLayout
