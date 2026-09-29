import { useState } from 'react'
import { router, useForm } from '@inertiajs/react'
import { Mail, PencilLine, Phone, Plus, Trash2, Users } from 'lucide-react'
import {
  Button,
  Card,
  CardHeader,
  ConfirmDialog,
  Modal,
  MoreMenu,
  TextInput,
} from '@/components/common'
import { routeTo } from '@/constants'
import { formatUsPhone, toTitleCase } from '@/utils'

export interface ClientContact {
  readonly id: number
  readonly name: string
  readonly initials: string
  /** What they do at the client — "Owner", "Project Manager". Free text. */
  readonly role: string | null
  readonly email: string | null
  readonly phone: string | null
  readonly isPrimary: boolean
}

export interface ClientContactsListProps {
  clientId: number
  contacts: readonly ClientContact[]
  /** Suppresses the list's own "Add contact" trigger — for `ClientContactsCard`, which puts one in its header instead. */
  hideAddButton?: boolean
}

/**
 * A client's own people — one place to see and correct everyone on record,
 * shared by the client's own screen and its edit form.
 *
 * The bare list, with no card of its own — so it can sit inside whatever
 * wrapper the screen it's on already uses (`ClientContactsCard` below for a
 * standalone card, or straight inside another card's own layout).
 */
export function ClientContactsList({ clientId, contacts, hideAddButton = false }: ClientContactsListProps) {
  const [adding, setAdding] = useState(false)
  const [editingContact, setEditingContact] = useState<ClientContact | null>(null)
  const [removingContact, setRemovingContact] = useState<ClientContact | null>(null)

  return (
    <>
      {contacts.length === 0 ? (
        <p className="text-md text-white/70">
          No one on record yet. Add whoever answers the phone for this client.
        </p>
      ) : (
        <ul className="space-y-2">
          {contacts.map((contact) => (
            <li
              key={contact.id}
              className="flex items-start gap-3 rounded-panel border border-hairline bg-white/4 p-3"
            >
              <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-brand/20 text-sm font-semibold text-brand">
                {contact.initials}
              </span>
              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-x-2">
                  <span className="truncate text-md font-medium text-white">{contact.name}</span>
                  {contact.isPrimary && (
                    <span className="text-2xs tracking-wide text-brand uppercase">Primary</span>
                  )}
                </span>
                {contact.role && <span className="block text-sm text-white/60">{contact.role}</span>}
                <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/70">
                  {contact.phone && (
                    <span className="flex items-center gap-1.5">
                      <Phone size={12} aria-hidden className="shrink-0" />
                      {contact.phone}
                    </span>
                  )}
                  {contact.email && (
                    <span className="flex items-center gap-1.5">
                      <Mail size={12} aria-hidden className="shrink-0" />
                      {contact.email}
                    </span>
                  )}
                </span>
              </span>
              <MoreMenu
                ariaLabel={`Actions for ${contact.name}`}
                variant="minimal"
                items={[
                  {
                    label: 'Edit',
                    icon: PencilLine,
                    onSelect: () => setEditingContact(contact),
                  },
                  {
                    label: 'Remove',
                    icon: Trash2,
                    destructive: true,
                    onSelect: () => setRemovingContact(contact),
                  },
                ]}
              />
            </li>
          ))}
        </ul>
      )}

      {!hideAddButton && (
        <Button
          type="button"
          variant="white"
          size="sm"
          className="mt-3"
          leftIcon={Plus}
          onClick={() => setAdding(true)}
        >
          Add Contact
        </Button>
      )}

      {adding && <ContactDialog clientId={clientId} onClose={() => setAdding(false)} />}

      {editingContact && (
        <ContactDialog
          clientId={clientId}
          contact={editingContact}
          onClose={() => setEditingContact(null)}
        />
      )}

      <ConfirmDialog
        isOpen={removingContact !== null}
        tone="danger"
        title={`Remove “${removingContact?.name ?? ''}”?`}
        description="They come off this client's own book."
        confirmLabel="Remove contact"
        confirmVariant="danger"
        onConfirm={() => {
          if (removingContact) {
            router.delete(routeTo.clientContact(clientId, removingContact.id))
          }
          setRemovingContact(null)
        }}
        onCancel={() => setRemovingContact(null)}
      />
    </>
  )
}

export interface ClientContactsCardProps {
  clientId: number
  contacts: readonly ClientContact[]
}

/** `ClientContactsList` in its own standalone card — the client's own screen. */
export function ClientContactsCard({ clientId, contacts }: ClientContactsCardProps) {
  const [adding, setAdding] = useState(false)

  return (
    <Card padding="md" className="min-w-0">
      <CardHeader
        title={
          <span className="flex items-center gap-2">
            <Users size={18} aria-hidden className="text-white/70" />
            Contacts
          </span>
        }
        actions={
          <button
            type="button"
            onClick={() => setAdding(true)}
            className="text-sm text-brand hover:underline"
          >
            Add Contact →
          </button>
        }
        className="border-b border-hairline pb-4"
        titleClassName="text-lg font-semibold"
      />
      <ClientContactsList clientId={clientId} contacts={contacts} hideAddButton />

      {adding && <ContactDialog clientId={clientId} onClose={() => setAdding(false)} />}
    </Card>
  )
}

interface ContactDialogProps {
  clientId: number
  /** Editing an existing contact when set; adding a new one when omitted. */
  contact?: ClientContact
  onClose: () => void
}

/** One form for both adding and correcting a contact, keyed on which it is. */
function ContactDialog({ clientId, contact, onClose }: ContactDialogProps) {
  const isEditing = contact !== undefined

  const form = useForm({
    name: contact?.name ?? '',
    role: contact?.role ?? '',
    email: contact?.email ?? '',
    phone: contact?.phone ?? '',
  })

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    const options = { preserveScroll: true, onSuccess: onClose }

    if (isEditing) {
      form.put(routeTo.clientContact(clientId, contact.id), options)
    } else {
      form.post(routeTo.clientContacts(clientId), options)
    }
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={isEditing ? 'Edit contact' : 'Add contact'}
      size="md"
      footer={
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="client-contact-form" isLoading={form.processing}>
            Save contact
          </Button>
        </div>
      }
    >
      <form id="client-contact-form" onSubmit={submit} className="flex flex-col gap-4">
        <TextInput
          id="contact-name"
          label="Name*"
          placeholder="e.g. Dana Wu"
          autoComplete="off"
          value={form.data.name}
          onChange={(event) => form.setData('name', toTitleCase(event.target.value))}
          {...(form.errors.name ? { error: form.errors.name } : {})}
        />
        <TextInput
          id="contact-role"
          label="Role"
          placeholder="e.g. Owner, Project Manager"
          autoComplete="off"
          value={form.data.role}
          onChange={(event) => form.setData('role', event.target.value)}
          {...(form.errors.role ? { error: form.errors.role } : {})}
        />
        <TextInput
          id="contact-email"
          type="email"
          label="Email"
          placeholder="e.g. dana@example.com"
          autoComplete="off"
          value={form.data.email}
          onChange={(event) => form.setData('email', event.target.value)}
          {...(form.errors.email ? { error: form.errors.email } : {})}
        />
        <TextInput
          id="contact-phone"
          type="tel"
          inputMode="tel"
          label="Phone"
          placeholder="(415) 555-0134"
          autoComplete="off"
          value={form.data.phone}
          onChange={(event) => form.setData('phone', formatUsPhone(event.target.value))}
          {...(form.errors.phone ? { error: form.errors.phone } : {})}
        />
      </form>
    </Modal>
  )
}
