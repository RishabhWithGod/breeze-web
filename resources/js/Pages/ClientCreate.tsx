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
  TextArea,
  TextInput,
  UnfinishedTakeoffNotice,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { useDisclosure } from '@/hooks'
import { ROUTES } from '@/constants'
import type { DraftAddress, ResumableTakeoff } from '@/types'
import { emptyAddress } from '@/utils'

interface ClientDraft {
  name: string
  notes: string
  addresses: DraftAddress[]
}

export interface ClientCreateProps {
  /**
   * A takeoff already part-way through, if there is one. Starting a second
   * client is normal; doing it by accident and losing track of the first is
   * not, so this screen says so before it happens.
   */
  unfinishedTakeoff: ResumableTakeoff | null
}

/**
 * Add Client.
 *
 * Who the work is for, and where they have work — nothing else. The work
 * itself is a project under them, and a drawing is uploaded against one of
 * those, so neither belongs on this screen.
 */
export default function ClientCreate({ unfinishedTakeoff }: ClientCreateProps) {
  const confirmNew = useDisclosure()

  const { data, setData, post, transform, processing, errors, hasErrors, clearErrors } =
    useForm<ClientDraft>({
      name: '',
      notes: '',
      addresses: [emptyAddress()],
    })

  /**
   * Inertia keeps server errors until the next request, which would leave
   * "Client name is required" sitting under a field the user has just filled
   * in, so each edit clears its own message.
   */
  const update = <K extends FormDataKeys<ClientDraft>>(
    field: K,
    value: FormDataValues<ClientDraft, K>,
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

    post(ROUTES.clients)
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
      <Head title="Add Client" />

      <PageHeader
        title="Add Client"
        breadcrumbs={[
          { label: 'Clients', href: ROUTES.clients },
          { label: 'New Client' },
        ]}
        actions={
          <ButtonLink href={ROUTES.clients} variant="secondary" leftIcon={ArrowLeft}>
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
              id="client-name"
              label="Client Name*"
              placeholder="e.g. Harborview Data Hall"
              autoComplete="off"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
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
              id="client-notes"
              label="Notes"
              rows={4}
              maxLength={2000}
              placeholder="Anything worth knowing about this client."
              value={data.notes}
              onChange={(event) => update('notes', event.target.value)}
              addon={`${data.notes.length}/2000`}
              {...(errors.notes ? { error: errors.notes } : {})}
            />
          </div>
        </Card>

        <div className="flex flex-wrap items-center justify-end gap-3">
          <ButtonLink href={ROUTES.clients} variant="white">
            Cancel
          </ButtonLink>
          <Button type="submit" leftIcon={FolderKanban} isLoading={processing}>
            Add Client
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

ClientCreate.layout = appLayout
