import { useState } from 'react'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Save } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  RadioGroup,
  SectionHeading,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { UploadDropzone } from '@/components/upload'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  DOCUMENT_DROPZONE_ACCEPT,
  DOCUMENT_SUPPORTED_FORMATS,
  DOCUMENT_TYPE_VALUES,
  ROUTES,
  routeTo,
} from '@/constants'
import type { DocumentFolderOption, DocumentJobOption, ImportableUpload } from '@/types'

export interface DocumentUploadProps {
  jobId: number | null
  jobs: readonly DocumentJobOption[]
  folders: readonly DocumentFolderOption[]
  importableUploads: readonly ImportableUpload[]
  maxFileSizeMb: number
}

type UploadMode = 'file' | 'import'

interface UploadDocumentForm {
  file: File | null
  upload_id: string
  name: string
  document_type: string
  job_id: string
  folder_id: string
  description: string
  visibility: 'team' | 'private'
}

/**
 * Upload Document — a real screen, not a popup, so a large drawing upload
 * survives a refresh and the flow can be linked to directly (e.g. from a
 * Job's own Documents section, which preselects and locks the job).
 */
export default function DocumentUpload({ jobId, jobs, folders, importableUploads, maxFileSizeMb }: DocumentUploadProps) {
  const [fileName, setFileName] = useState<string | null>(null)
  const [mode, setMode] = useState<UploadMode>('file')

  const { data, setData, post, processing, errors, hasErrors, clearErrors, transform } = useForm<UploadDocumentForm>({
    file: null,
    upload_id: '',
    name: '',
    document_type: 'Other',
    job_id: jobId ? String(jobId) : 'none',
    folder_id: 'none',
    description: '',
    visibility: 'team',
  })

  transform((form) => ({
    ...form,
    job_id: form.job_id === 'none' ? null : form.job_id,
    folder_id: form.folder_id === 'none' ? null : form.folder_id,
  }))

  const jobFolders = folders.filter(
    (folder) => data.job_id === 'none' || folder.job_id === null || String(folder.job_id) === data.job_id,
  )

  const canSubmit = mode === 'file' ? Boolean(data.file) : Boolean(data.upload_id)
  const cancelHref = jobId ? routeTo.job(jobId) : ROUTES.documents

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    const url = mode === 'file' ? ROUTES.documents : routeTo.documentImportUpload
    post(url, { forceFormData: mode === 'file', preserveScroll: true })
  }

  return (
    <PageTransition>
      <Head title="Upload Document" />

      <PageHeader
        title="Upload Document"
        subtitle="Add a drawing, specification or other project file."
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
            <RadioGroup
              name="document-upload-mode"
              label="Source"
              columns={2}
              options={[
                { label: 'Upload a file', value: 'file' },
                { label: 'Import from AI Takeoff', value: 'import' },
              ]}
              value={mode}
              onChange={(value) => setMode(value as UploadMode)}
            />

            {mode === 'file' ? (
              <>
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
              </>
            ) : importableUploads.length === 0 ? (
              <p className="text-md text-white/75">
                No AI Takeoff drawings are available to import — every existing one is already registered as a document.
              </p>
            ) : (
              <SelectField
                id="document-import-upload"
                label="AI Takeoff Drawing"
                options={[
                  { label: 'Choose a drawing…', value: '' },
                  ...importableUploads.map((upload) => ({
                    label: upload.projectName ? `${upload.label} — ${upload.projectName}` : upload.label,
                    value: String(upload.id),
                  })),
                ]}
                value={data.upload_id}
                onChange={(event) => setData('upload_id', event.target.value)}
                error={errors.upload_id}
              />
            )}

            <TextInput
              id="document-name"
              label="Document Name"
              placeholder={fileName ?? 'Defaults to the file name'}
              value={data.name}
              onChange={(event) => setData('name', event.target.value)}
              error={errors.name}
            />

            <div className="grid gap-6 sm:grid-cols-2">
              <SelectField
                id="document-type"
                label="Document Type"
                options={DOCUMENT_TYPE_VALUES.map((type) => ({ label: type, value: type }))}
                value={data.document_type}
                onChange={(event) => setData('document_type', event.target.value)}
                error={errors.document_type}
              />
              <SelectField
                id="document-job"
                label="Job"
                disabled={Boolean(jobId)}
                options={[{ label: 'No Job', value: 'none' }, ...jobs.map((job) => ({ label: job.name, value: String(job.id) }))]}
                value={data.job_id}
                onChange={(event) => setData('job_id', event.target.value)}
              />
            </div>

            {mode === 'file' && (
              <SelectField
                id="document-folder"
                label="Folder"
                options={[{ label: 'No Folder', value: 'none' }, ...jobFolders.map((folder) => ({ label: folder.name, value: String(folder.id) }))]}
                value={data.folder_id}
                onChange={(event) => setData('folder_id', event.target.value)}
              />
            )}

            {mode === 'file' && (
              <TextArea
                id="document-description"
                label="Description / Notes (optional)"
                rows={4}
                value={data.description}
                onChange={(event) => setData('description', event.target.value)}
                error={errors.description}
              />
            )}

            <RadioGroup
              name="document-visibility"
              label="Visibility"
              columns={2}
              options={[
                { label: 'Team — everyone can see it', value: 'team' },
                { label: 'Private — only you and managers', value: 'private' },
              ]}
              value={data.visibility}
              onChange={(value) => setData('visibility', value as 'team' | 'private')}
            />
          </div>

          <div className="mt-8 flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-6">
            <ButtonLink href={cancelHref} variant="white">
              Cancel
            </ButtonLink>
            <Button type="submit" leftIcon={Save} isLoading={processing} disabled={!canSubmit}>
              {mode === 'file' ? 'Upload' : 'Add Document'}
            </Button>
          </div>
        </Card>
      </form>
    </PageTransition>
  )
}

DocumentUpload.layout = appLayout
