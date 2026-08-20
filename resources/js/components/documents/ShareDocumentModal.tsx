import { useForm } from '@inertiajs/react'
import { Button, Modal, SelectField } from '@/components/common'
import { routeTo } from '@/constants'
import type { Document } from '@/types'

export interface ShareableUser {
  readonly id: number
  readonly name: string
}

export interface ShareDocumentModalProps {
  isOpen: boolean
  onClose: () => void
  document: Document | null
  users: readonly ShareableUser[]
}

/** Real user-to-user sharing through `DocumentController::share`. */
export function ShareDocumentModal({ isOpen, onClose, document: doc, users }: ShareDocumentModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({
    user_id: '',
    permission: 'view',
  })

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    if (!doc) return
    post(routeTo.documentShare(doc.id), { onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title={doc ? `Share “${doc.name}”` : 'Share Document'}
      description="They'll see it under Shared with Me and be notified."
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button onClick={submit} isLoading={processing} disabled={!data.user_id}>
            Share Document
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <SelectField
          id="share-user"
          label="Share with"
          options={[{ label: 'Choose a person…', value: '' }, ...users.map((user) => ({ label: user.name, value: String(user.id) }))]}
          value={data.user_id}
          onChange={(event) => setData('user_id', event.target.value)}
          error={errors.user_id}
        />
        <SelectField
          id="share-permission"
          label="Permission"
          options={[
            { label: 'Can view', value: 'view' },
            { label: 'Can edit', value: 'edit' },
          ]}
          value={data.permission}
          onChange={(event) => setData('permission', event.target.value)}
        />
      </div>
    </Modal>
  )
}
