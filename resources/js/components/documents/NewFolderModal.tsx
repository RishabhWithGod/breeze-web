import { useForm } from '@inertiajs/react'
import { Button, Modal, SelectField, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { DocumentFolderOption, DocumentJobOption } from '@/types'

export interface NewFolderModalProps {
  isOpen: boolean
  onClose: () => void
  jobs: readonly DocumentJobOption[]
  folders: readonly DocumentFolderOption[]
}

/** Real folder creation through `DocumentFolderController::store`. */
export function NewFolderModal({ isOpen, onClose, jobs, folders }: NewFolderModalProps) {
  const { data, setData, post, processing, errors, reset, transform } = useForm({
    name: '',
    parent_id: 'none',
    job_id: 'none',
  })

  transform((form) => ({
    name: form.name,
    parent_id: form.parent_id === 'none' ? null : form.parent_id,
    job_id: form.job_id === 'none' ? null : form.job_id,
  }))

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    post(routeTo.documentFoldersStore, { onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title="New Folder"
      description="Group related documents together."
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button onClick={submit} isLoading={processing} disabled={!data.name.trim()}>
            Create Folder
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <TextInput
          id="folder-name"
          label="Folder Name"
          placeholder="e.g. Electrical Drawings"
          value={data.name}
          onChange={(event) => setData('name', event.target.value)}
          error={errors.name}
        />
        <SelectField
          id="folder-parent"
          label="Parent Folder (optional)"
          options={[{ label: 'Top level', value: 'none' }, ...folders.map((folder) => ({ label: folder.name, value: String(folder.id) }))]}
          value={data.parent_id}
          onChange={(event) => setData('parent_id', event.target.value)}
        />
        <SelectField
          id="folder-job"
          label="Job (optional)"
          options={[{ label: 'No Job', value: 'none' }, ...jobs.map((job) => ({ label: job.name, value: String(job.id) }))]}
          value={data.job_id}
          onChange={(event) => setData('job_id', event.target.value)}
        />
      </div>
    </Modal>
  )
}
