import { useState } from 'react'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Save } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  SectionHeading,
  TextArea,
  TextInput,
} from '@/components/common'
import { UploadDropzone } from '@/components/upload'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  DOCUMENT_DROPZONE_ACCEPT,
  DOCUMENT_SUPPORTED_FORMATS,
  ROUTES,
  routeTo,
} from '@/constants'

export interface DocumentUploadProps {
  jobId: number | null
  /** The takeoff this document is being filed under, when opened from one. */
  projectId: number | null
  maxFileSizeMb: number
}

interface UploadDocumentForm {
  file: File | null
  name: string
  /** The takeoff it is filed under. Carried, not asked for. */
  project_id: string
  description: string
}

/**
 * Upload Document — a real screen, not a popup, so a large drawing upload
 * survives a refresh and the flow can be linked to directly (e.g. from a
 * Job's own Documents section, which preselects and locks the job).
 */
export default function DocumentUpload({
  jobId,
  projectId,
  maxFileSizeMb,
}: DocumentUploadProps) {
  const [fileName, setFileName] = useState<string | null>(null)

  const { data, setData, post, processing, errors, hasErrors, clearErrors } = useForm<UploadDocumentForm>({
    file: null,
    name: '',
    // Filed under the takeoff whose screen asked for it — not a field, because
    // the answer is already known.
    project_id: projectId ? String(projectId) : '',
    description: '',
  })

  const canSubmit = Boolean(data.file)
  const cancelHref = projectId
    ? routeTo.projectDocuments(projectId)
    : jobId
      ? routeTo.job(jobId)
      : ROUTES.documents

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    post(ROUTES.documents, { forceFormData: true, preserveScroll: true })
  }

  return (
    <PageTransition>
      <Head title="Upload Document" />

      <PageHeader
        title="Upload Document"
        subtitle="Add a drawing, specification or other client file."
        breadcrumbs={[{ label: 'Documents', href: ROUTES.documents }, { label: 'Upload' }]}
        actions={
          <ButtonLink href={cancelHref} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the form" className="mb-6">
          Some fields need attention before this document can be saved.
        </Alert>
      )}

      <form onSubmit={submit} noValidate>
        <Card padding="lg">
          <SectionHeading title="Document details" />

          <div className="space-y-6">
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

            <TextInput
              id="document-name"
              label="Document Name"
              placeholder={fileName ?? 'Defaults to the file name'}
              value={data.name}
              onChange={(event) => setData('name', event.target.value)}
              error={errors.name}
            />

            <TextArea
              id="document-description"
              label="Description / Notes (optional)"
              rows={4}
              value={data.description}
              onChange={(event) => setData('description', event.target.value)}
              error={errors.description}
            />
          </div>

          <div className="mt-8 flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-6">
            <ButtonLink href={cancelHref} variant="white">
              Cancel
            </ButtonLink>
            <Button type="submit" leftIcon={Save} isLoading={processing} disabled={!canSubmit}>
              Upload
            </Button>
          </div>
        </Card>
      </form>
    </PageTransition>
  )
}

DocumentUpload.layout = appLayout
