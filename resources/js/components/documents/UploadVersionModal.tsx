import { useState } from 'react'
import { useForm } from '@inertiajs/react'
import { Button, Modal, TextArea } from '@/components/common'
import { UploadDropzone } from '@/components/upload'
import { DOCUMENT_DROPZONE_ACCEPT, DOCUMENT_SUPPORTED_FORMATS, routeTo } from '@/constants'
import type { Document } from '@/types'

export interface UploadVersionModalProps {
  isOpen: boolean
  onClose: () => void
  document: Document | null
  maxFileSizeMb: number
}

/** Uploads a new version through `DocumentController::storeVersion` — the prior file stays in History. */
export function UploadVersionModal({ isOpen, onClose, document: doc, maxFileSizeMb }: UploadVersionModalProps) {
  const [fileName, setFileName] = useState<string | null>(null)

  const { data, setData, post, processing, errors, reset, clearErrors } = useForm<{ file: File | null; description: string }>({
    file: null,
    description: '',
  })

  const close = () => {
    reset()
    setFileName(null)
    onClose()
  }

  const submit = () => {
    if (!doc) return
    post(routeTo.documentVersions(doc.id), { forceFormData: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title={doc ? `Upload New Version — ${doc.name}` : 'Upload New Version'}
      description={doc ? `Currently ${doc.versionLabel}. The prior file stays available in History.` : undefined}
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button onClick={submit} isLoading={processing} disabled={!data.file}>
            Upload Version
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <UploadDropzone
          maxFiles={1}
          maxFileSizeMb={maxFileSizeMb}
          accept={DOCUMENT_DROPZONE_ACCEPT}
          supportedFormats={DOCUMENT_SUPPORTED_FORMATS}
          hasError={Boolean(errors.file)}
          isSuccess={Boolean(data.file) && !errors.file}
          onFilesAccepted={(files) => {
            const file = files[0] ?? null
            setData('file', file)
            setFileName(file?.name ?? null)
            if (errors.file) clearErrors('file')
          }}
        />
        {fileName && <p className="text-sm text-white/80">Selected: {fileName}</p>}
        {errors.file && <p className="text-sm text-red-300">{errors.file}</p>}

        <TextArea
          id="version-description"
          label="What changed? (optional)"
          rows={3}
          value={data.description}
          onChange={(event) => setData('description', event.target.value)}
        />
      </div>
    </Modal>
  )
}
