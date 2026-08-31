import { useState } from 'react'
import { Head, router, useForm, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  CalendarClock,
  FileText,
  FolderKanban,
  MapPin,
  Sparkles,
  Trash2,
  TriangleAlert,
  Upload as UploadIcon,
  User,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  ConfirmDialog,
  StatusChip,
} from '@/components/common'
import { ActivityFeed, DashboardPanel } from '@/components/dashboard'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ProjectDocumentList, ProjectPdfPicker } from '@/components/projects'
import { ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  ProjectActivityEntry,
  ProjectDocument,
  ProjectDocumentLimits,
  ProjectRecord,
  RejectedUploadFile,
  SharedPageProps,
} from '@/types'
import {
  JOB_TYPE_LABEL,
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  formatDate,
} from '@/utils'

export interface ProjectShowProps {
  project: ProjectRecord
  documents: readonly ProjectDocument[]
  activity: readonly ProjectActivityEntry[]
  limits: ProjectDocumentLimits
}

/** Adding drawings to a project that already exists. */
interface DocumentUpload {
  documents: File[]
  document_titles: string[]
}

/**
 * Project detail: the details it was created with, the drawing PDFs it holds, and
 * what has happened to it.
 *
 * PDFs are added and removed here rather than on the create screen, so a drawing
 * set can be completed as the drawings arrive.
 */
export default function ProjectShow({
  project,
  documents,
  activity,
  limits,
}: ProjectShowProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [rejected, setRejected] = useState<readonly RejectedUploadFile[]>([])
  const [pendingDelete, setPendingDelete] = useState<ProjectDocument | null>(null)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [startingTakeoff, setStartingTakeoff] = useState(false)
  const deleteDialog = useDisclosure()
  const deleteProjectDialog = useDisclosure()

  const startTakeoff = () => {
    router.post(routeTo.projectTakeoffStart(project.id), {}, {
      onStart: () => setStartingTakeoff(true),
      onFinish: () => setStartingTakeoff(false),
    })
  }

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  const { data, setData, post, processing, errors, reset, clearErrors } =
    useForm<DocumentUpload>({ documents: [], document_titles: [] })

  const addFiles = (files: File[]) => {
    setData('documents', [...data.documents, ...files])
    setData('document_titles', [...data.document_titles, ...files.map(() => '')])
    if (errors.documents) clearErrors('documents')
  }

  const removeFile = (index: number) => {
    setData(
      'documents',
      data.documents.filter((_, position) => position !== index),
    )
    setData(
      'document_titles',
      data.document_titles.filter((_, position) => position !== index),
    )
  }

  const setFileTitle = (index: number, title: string) => {
    setData(
      'document_titles',
      data.document_titles.map((current, position) =>
        position === index ? title : current,
      ),
    )
  }

  const submitFiles = (event: React.FormEvent) => {
    event.preventDefault()

    post(routeTo.projectDocuments(project.id), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => reset(),
    })
  }

  const confirmDocumentDelete = () => {
    if (!pendingDelete) return

    router.delete(routeTo.projectDocument(project.id, pendingDelete.id), {
      preserveScroll: true,
    })
    setPendingDelete(null)
    deleteDialog.close()
  }

  const details = [
    { icon: User, label: 'Client', value: project.client },
    { icon: MapPin, label: 'Site', value: project.location ?? 'Not recorded' },
    {
      icon: FolderKanban,
      label: 'Type',
      value: project.projectType
        ? `${JOB_TYPE_LABEL[project.projectType]} · ${project.discipline}`
        : project.discipline,
    },
    {
      icon: CalendarClock,
      label: 'Takeoff due',
      value: project.dueDate ? formatDate(project.dueDate) : 'Not set',
    },
    {
      icon: FileText,
      label: 'Drawings',
      value: `${documents.length} ${documents.length === 1 ? 'PDF' : 'PDFs'} on record`,
    },
  ]

  return (
    <PageTransition>
      <Head title={project.name} />

      <PageHeader
        title={project.name}
        subtitle={
          project.code ? `${project.code} · ${project.client}` : project.client
        }
        breadcrumbs={[
          { label: 'Clients', href: ROUTES.projects },
          { label: project.name },
        ]}
        actions={
          <>
            <StatusChip
              tone={TAKEOFF_STATUS_TONE[project.status]}
              label={TAKEOFF_STATUS_LABEL[project.status]}
            />
            {/* Shown once the engine has returned a result for this project. */}
            {project.takeoffUrl ? (
              <ButtonLink href={project.takeoffUrl} variant="secondary" leftIcon={FileText}>
                View takeoff
              </ButtonLink>
            ) : (
              <Button
                variant="secondary"
                leftIcon={Sparkles}
                onClick={startTakeoff}
                isLoading={startingTakeoff}
                disabled={documents.length === 0}
                title={
                  documents.length === 0
                    ? 'Add a drawing PDF before running a takeoff'
                    : undefined
                }
              >
                Run AI Takeoff
              </Button>
            )}
            <Button
              variant="white"
              leftIcon={Trash2}
              className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
              onClick={deleteProjectDialog.open}
            >
              Delete
            </Button>
          </>
        }
      />

      <AnimatePresence initial={false}>
        {notice && (
          <Alert
            key={notice}
            tone={flash.warning ? 'warning' : 'success'}
            className="mb-6"
            onDismiss={() => setDismissed(notice)}
          >
            {notice}
          </Alert>
        )}

        {rejected.length > 0 && (
          <Alert
            key="rejected"
            tone="warning"
            title={`${rejected.length} file(s) could not be added`}
            icon={TriangleAlert}
            className="mb-6"
            onDismiss={() => setRejected([])}
          >
            <ul className="mt-1 space-y-1">
              {rejected.map((item) => (
                <li key={item.name} className="text-sm">
                  <span className="font-medium text-white">{item.name}</span> —{' '}
                  {item.reason}
                </li>
              ))}
            </ul>
          </Alert>
        )}
      </AnimatePresence>

      <div className="grid gap-6 xl:grid-cols-3">
        {/* ------------------------------------------------------- Details ---- */}
        <Card padding="lg">
          <CardHeader title="Client details" />

          <dl className="space-y-4">
            {details.map((detail) => (
              <div key={detail.label} className="flex items-start gap-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-brand/15 text-brand">
                  <detail.icon size={16} aria-hidden />
                </span>
                <div className="min-w-0">
                  <dt className="text-sm text-white/70">{detail.label}</dt>
                  <dd className="text-md font-medium text-white">{detail.value}</dd>
                </div>
              </div>
            ))}
          </dl>

          {project.notes && (
            <div className="mt-6 border-t border-hairline pt-4">
              <p className="text-sm text-white/70">Notes for the takeoff</p>
              <p className="mt-1 text-md whitespace-pre-line text-white">
                {project.notes}
              </p>
            </div>
          )}
        </Card>

        {/* --------------------------------------------------- Drawing PDFs --- */}
        <Card padding="lg" className="xl:col-span-2" index={1}>
          <CardHeader
            title="Drawing PDFs"
            subtitle="The drawings a takeoff runs against. Open one to read it, or add the rest of the set."
          />

          <ProjectDocumentList
            documents={documents}
            disabled={processing}
            onRemove={(document) => {
              setPendingDelete(document)
              deleteDialog.open()
            }}
          />

          <form onSubmit={submitFiles} className="mt-6 border-t border-hairline pt-6">
            <ProjectPdfPicker
              files={data.documents}
              titles={data.document_titles}
              onAdd={addFiles}
              onRemove={removeFile}
              onTitleChange={setFileTitle}
              onReject={(items) => setRejected(items)}
              maxFiles={limits.maxFiles}
              maxFileSizeMb={limits.maxFileSizeMb}
              disabled={processing}
              fileErrors={errors as Record<string, string>}
              {...(errors.documents ? { error: errors.documents } : {})}
            />

            {data.documents.length > 0 && (
              <div className="mt-4 flex flex-wrap items-center justify-end gap-3">
                <Button
                  type="button"
                  variant="white"
                  onClick={() => reset()}
                  disabled={processing}
                >
                  Clear
                </Button>
                <Button type="submit" leftIcon={UploadIcon} isLoading={processing}>
                  Add {data.documents.length}{' '}
                  {data.documents.length === 1 ? 'PDF' : 'PDFs'}
                </Button>
              </div>
            )}
          </form>
        </Card>
      </div>

      {/* --------------------------------------------------------- Activity --- */}
      {activity.length > 0 && (
        <DashboardPanel title="Client Activity" className="mt-6" index={2}>
          <ActivityFeed entries={activity} />
        </DashboardPanel>
      )}

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Remove “${pendingDelete?.label ?? ''}”?`}
        description="The PDF is deleted from the client and from storage. This cannot be undone."
        confirmLabel="Remove drawing"
        confirmVariant="danger"
        onConfirm={confirmDocumentDelete}
        onCancel={() => {
          setPendingDelete(null)
          deleteDialog.close()
        }}
      />

      <ConfirmDialog
        isOpen={deleteProjectDialog.isOpen}
        tone="danger"
        title={`Delete “${project.name}”?`}
        description="The client is removed from your list. Its drawings stay on file."
        confirmLabel="Delete client"
        confirmVariant="danger"
        onConfirm={() => {
          router.delete(routeTo.project(project.id))
          deleteProjectDialog.close()
        }}
        onCancel={deleteProjectDialog.close}
      />
    </PageTransition>
  )
}

ProjectShow.layout = appLayout
