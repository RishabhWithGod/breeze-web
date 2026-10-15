import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, DollarSign, FolderKanban, Globe, Mail, Phone } from 'lucide-react'
import {
  AddressListField,
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  ConfirmDialog,
  RequiredFieldsNotice,
  TextArea,
  TextInput,
  UnfinishedTakeoffNotice,
} from '@/components/common'
import { ProjectMemberPicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { useDisclosure } from '@/hooks'
import { ROUTES } from '@/constants'
import type { ClientTeamMemberOption, DraftAddress, ResumableTakeoff } from '@/types'
import { cleanAmountInput, emptyAddress, formatAmountInput, formatUsPhone, toTitleCase } from '@/utils'

interface ClientDraft {
  name: string
  website: string
  contact_email: string
  contact_phone: string
  notes: string
  labor_rate: string
  member_ids: string[]
  addresses: DraftAddress[]
}

export interface ClientCreateProps {
  /**
   * A takeoff already part-way through, if there is one. Starting a second
   * client is normal; doing it by accident and losing track of the first is
   * not, so this screen says so before it happens.
   */
  unfinishedTakeoff: ResumableTakeoff | null
  /** What the labor rate field starts at — the configured default. */
  defaultLaborRate: number
  /** Everyone on the register, offered as this client's members regardless of team. */
  members: readonly (ClientTeamMemberOption & { readonly teamId: number | null })[]
  /** Invited but not yet on the register — shown, not selectable, until they accept. */
  pendingMembers: readonly { readonly id: number; readonly name: string; readonly roleLabel: string }[]
}

/**
 * Add Client.
 *
 * Who the work is for, and where they have work — nothing else. The work
 * itself is a project under them, and a drawing is uploaded against one of
 * those, so neither belongs on this screen.
 */
export default function ClientCreate({ unfinishedTakeoff, defaultLaborRate, members, pendingMembers }: ClientCreateProps) {
  const confirmNew = useDisclosure()

  const { data, setData, post, transform, processing, errors, hasErrors, clearErrors } =
    useForm<ClientDraft>({
      name: '',
      website: '',
      contact_email: '',
      contact_phone: '',
      notes: '',
      labor_rate: String(defaultLaborRate),
      member_ids: [],
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
        <RequiredFieldsNotice fields={['Client Name']} />
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
              onChange={(event) => update('name', toTitleCase(event.target.value))}
              {...(errors.name ? { error: errors.name } : {})}
            />

            <div className="grid gap-6 sm:grid-cols-2">
              <TextInput
                id="client-contact-email"
                type="email"
                leftIcon={Mail}
                label="Contact Email"
                placeholder="e.g. dana@example.com"
                autoComplete="off"
                value={data.contact_email}
                onChange={(event) => update('contact_email', event.target.value)}
                {...(errors.contact_email ? { error: errors.contact_email } : {})}
              />

              <TextInput
                id="client-contact-phone"
                type="tel"
                inputMode="tel"
                leftIcon={Phone}
                label="Contact Phone"
                placeholder="(415) 555-0134"
                autoComplete="off"
                value={data.contact_phone}
                onChange={(event) => update('contact_phone', formatUsPhone(event.target.value))}
                {...(errors.contact_phone ? { error: errors.contact_phone } : {})}
              />
            </div>

            <div className="grid gap-6 sm:grid-cols-2">
              <TextInput
                id="client-labor-rate"
                type="text"
                inputMode="decimal"
                leftIcon={DollarSign}
                label="Labor Rate ($/hr)"
                placeholder="e.g. 50"
                hint="What an hour of this client's labor is billed at. Every estimate on their projects prices labor at this rate."
                value={formatAmountInput(data.labor_rate)}
                onChange={(event) => update('labor_rate', cleanAmountInput(event.target.value))}
                {...(errors.labor_rate ? { error: errors.labor_rate } : {})}
              />

              <TextInput
                id="client-website"
                type="text"
                leftIcon={Globe}
                label="Website"
                placeholder="e.g. www.coldbar.com"
                autoComplete="off"
                value={data.website}
                onChange={(event) => update('website', event.target.value)}
                {...(errors.website ? { error: errors.website } : {})}
              />
            </div>

            {/*
              Who works for this client — the whole crew register, not
              filtered by team. A member picked here who isn't on a team yet
              is unaffected; team assignment happens elsewhere.
            */}
            <ProjectMemberPicker
              label="Members"
              required
              allowNoTeam
              teamId={null}
              teamName={null}
              members={members}
              pendingMembers={pendingMembers}
              value={data.member_ids}
              onChange={(ids) => update('member_ids', ids)}
              disabled={processing}
              {...(errors.member_ids ? { error: errors.member_ids } : {})}
            />

            <div className="border-t border-hairline pt-6">
              <p className="mb-4 text-md font-medium text-white">Addresses</p>

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
