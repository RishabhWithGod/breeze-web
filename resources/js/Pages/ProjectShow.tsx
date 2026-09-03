import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  FileText,
  FolderKanban,
  MapPin,
  Sparkles,
  Trash2,
  Upload as UploadIcon,
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
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ProjectDocumentList } from '@/components/projects'
import { ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  ProjectDocument,
  ProjectRecord,
  SharedPageProps,
} from '@/types'
import {
  JOB_TYPE_LABEL,
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
} from '@/utils'

export interface ProjectShowProps {
  project: ProjectRecord
  documents: readonly ProjectDocument[]
}

/**
 * Project detail: the details it was opened with, the drawing PDFs it holds,
 * and what has happened to it.
 *
 * Drawings can be opened and removed here but not added — a PDF only ever
 * arrives through AI Takeoff, which uploads it against a client that already
 * exists.
 */
export default function ProjectShow({
  project,
  documents,
}: ProjectShowProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [pendingDelete, setPendingDelete] = useState<ProjectDocument | null>(null)
  const [selecting, setSelecting] = useState(false)
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

  /*
   * Choosing is a real write — every later takeoff, and the drawing name shown
   * on the Projects list, follow it — so it posts rather than being held in the
   * page. Only offered with more than one drawing: with one there is nothing
   * to choose, and the fallback already points at it.
   */
  const selectDrawing = (document: ProjectDocument) => {
    if (document.id === project.selectedUploadId) return

    router.post(
      routeTo.projectDocumentSelect(project.id, document.id),
      {},
      {
        preserveScroll: true,
        onStart: () => setSelecting(true),
        onFinish: () => setSelecting(false),
      },
    )
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
    { icon: MapPin, label: 'Site', value: project.location ?? 'Not recorded' },
    {
      icon: FolderKanban,
      label: 'Type',
      value: project.projectType
        ? `${JOB_TYPE_LABEL[project.projectType]} · ${project.discipline}`
        : project.discipline,
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

      {/* The name is the client's own, so only a project number adds anything
          to it — the subtitle would otherwise just repeat the title. */}
      <PageHeader
        title={project.name}
        {...(project.code ? { subtitle: project.code } : {})}
        breadcrumbs={[
          { label: 'Projects', href: ROUTES.projects },
          { label: project.name },
        ]}
        actions={
          <>
            <StatusChip
              tone={TAKEOFF_STATUS_TONE[project.status]}
              label={TAKEOFF_STATUS_LABEL[project.status]}
            />
            {/*
              Three states, in the order the work happens: read the takeoff once
              there is one; otherwise run it if a drawing is on record; otherwise
              go and upload one, which is the only way a drawing gets here.
            */}
            {project.takeoffUrl ? (
              <ButtonLink href={project.takeoffUrl} variant="secondary" leftIcon={FileText}>
                View takeoff
              </ButtonLink>
            ) : documents.length === 0 ? (
              <ButtonLink
                href={routeTo.uploadForProject(project.id)}
                variant="secondary"
                leftIcon={UploadIcon}
              >
                Upload a drawing
              </ButtonLink>
            ) : (
              <Button
                variant="secondary"
                leftIcon={Sparkles}
                onClick={startTakeoff}
                isLoading={startingTakeoff}
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
      </AnimatePresence>

      <div className="grid gap-6 xl:grid-cols-3">
        {/* ------------------------------------------------------- Details ---- */}
        <Card padding="lg">
          <CardHeader title="Project details" />

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
            subtitle={
              documents.length > 1
                ? 'Every drawing on record. Pick the one the next takeoff should run against.'
                : 'Every drawing on record for this client.'
            }
          />

          <ProjectDocumentList
            documents={documents}
            selectedId={project.selectedUploadId}
            disabled={selecting}
            {...(documents.length > 1 ? { onSelect: selectDrawing } : {})}
            onRemove={(document) => {
              setPendingDelete(document)
              deleteDialog.open()
            }}
          />
        </Card>
      </div>

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
