import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { useDropzone, type FileRejection } from 'react-dropzone'
import {
  ArrowLeft,
  FileText,
  FolderKanban,
  MapPin,
  Pencil,
  Sparkles,
  Trash2,
  TriangleAlert,
  Upload as UploadIcon,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  ConfirmDialog,
  IconButton,
  StatusChip,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ProjectDocumentList } from '@/components/projects'
import { DROPZONE_ACCEPT, MAX_FILE_SIZE_MB, MAX_FILES, ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  ProjectDocument,
  ProjectRecord,
  SharedPageProps,
} from '@/types'
import {
  cn,
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
 * Drawings can be opened, removed, and added right from the "Drawing PDFs"
 * tile (click or drag a PDF onto it) — posted through the same upload
 * endpoint the standalone AI Takeoff screen uses.
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

  /*
   * Drawing PDFs tile: drag-and-drop / click-to-browse straight onto this
   * project, posted to the same `ROUTES.upload` endpoint the standalone AI
   * Takeoff upload screen uses — deliberately NOT the shared upload store
   * (that page's own queue), so this stays self-contained and can't leak
   * state into or out of that flow.
   */
  const [queuedFiles, setQueuedFiles] = useState<File[]>([])
  const [rejectedFiles, setRejectedFiles] = useState<{ name: string; reason: string }[]>([])
  const [uploadError, setUploadError] = useState<string | null>(null)
  const [isUploadingDrawing, setIsUploadingDrawing] = useState(false)

  const onDropDrawing = useCallback((accepted: File[], rejections: FileRejection[]) => {
    if (accepted.length > 0) {
      setUploadError(null)
      setQueuedFiles((prev) => [...prev, ...accepted].slice(0, MAX_FILES))
    }
    if (rejections.length > 0) {
      setRejectedFiles(
        rejections.map((rejection) => ({
          name: rejection.file.name,
          reason:
            rejection.errors[0]?.code === 'file-too-large'
              ? `Exceeds the ${MAX_FILE_SIZE_MB} MB limit`
              : (rejection.errors[0]?.message ?? 'File was rejected'),
        })),
      )
    }
  }, [])

  const { getRootProps, getInputProps, open: openFilePicker, isDragActive } = useDropzone({
    onDrop: onDropDrawing,
    accept: DROPZONE_ACCEPT,
    maxSize: MAX_FILE_SIZE_MB * 1024 * 1024,
    maxFiles: MAX_FILES,
    multiple: MAX_FILES > 1,
    noClick: true,
    noKeyboard: true,
    disabled: isUploadingDrawing,
  })
  // The drag handlers are all this needs from getRootProps() — `ref` is
  // dropped because the wrapping div below is a plain element (no
  // forwardRef needed), and losing it costs nothing beyond react-dropzone's
  // own internal focus/measurement niceties.
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const { ref: _dropzoneRef, ...dropzoneRootProps } = getRootProps()

  const removeQueuedFile = (index: number) =>
    setQueuedFiles((prev) => prev.filter((_, i) => i !== index))

  const uploadQueuedDrawings = () => {
    if (queuedFiles.length === 0 || isUploadingDrawing) return

    setIsUploadingDrawing(true)
    setUploadError(null)
    router.post(
      ROUTES.upload,
      { project_id: project.id, files: queuedFiles },
      {
        forceFormData: true,
        onError: (errors) => {
          setUploadError(errors['project_id'] ?? errors['files'] ?? 'Upload failed — please try again.')
        },
        onFinish: () => setIsUploadingDrawing(false),
      },
    )
  }

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
              Two states, in the order the work happens: read the takeoff once
              there is one; otherwise run it once a drawing is on record.
              Uploading a drawing now happens inline in the "Drawing PDFs"
              tile below, not from here.
            */}
            {project.takeoffUrl ? (
              <ButtonLink href={project.takeoffUrl} variant="secondary" leftIcon={FileText}>
                View takeoff
              </ButtonLink>
            ) : documents.length > 0 ? (
              <Button
                variant="secondary"
                leftIcon={Sparkles}
                onClick={startTakeoff}
                isLoading={startingTakeoff}
              >
                Run AI Takeoff
              </Button>
            ) : null}
            <ButtonLink href={routeTo.projectEdit(project.id)} variant="secondary" leftIcon={Pencil}>
              Edit
            </ButtonLink>
            <Button
              variant="white"
              leftIcon={Trash2}
              className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
              onClick={deleteProjectDialog.open}
            >
              Delete
            </Button>
            <ButtonLink href={ROUTES.projects} variant="secondary" leftIcon={ArrowLeft}>
              Back
            </ButtonLink>
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
        <Card accent="brand" padding="lg">
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

        {/* --------------------------------------------------- Drawing PDFs ---
            The whole tile is a drop target and, outside its existing rows,
            a click target too: dragging a PDF onto it anywhere, or clicking
            any non-interactive part of it, opens the file picker. Clicks on
            the document list itself (select / open / remove) are shielded
            with stopPropagation so they don't also pop the picker. */}
        {/* A plain div carries the drag/click handlers — react-dropzone's
            root props type as native DOM attributes (e.g. `onDrag`), which
            collide with framer-motion's own gesture props of the same name
            on `Card` (a `motion.div`) if spread there directly. */}
        <div
          {...dropzoneRootProps}
          onClick={() => {
            if (!isUploadingDrawing) openFilePicker()
          }}
          className="cursor-pointer xl:col-span-2"
        >
          <input {...getInputProps()} aria-label="Upload a drawing PDF" />

          <Card
            accent="success"
            padding="lg"
            index={1}
            className={cn(
              'transition-colors',
              isDragActive && 'border-brand bg-brand/8 ring-2 ring-brand/40',
            )}
          >
          <CardHeader
            title="Drawing PDFs"
            subtitle={
              documents.length > 1
                ? 'Every drawing on record. Pick the one the next takeoff should run against.'
                : 'Every drawing on record for this client. Click or drag a PDF here to add another.'
            }
            actions={
              <Button
                variant="secondary"
                size="sm"
                leftIcon={UploadIcon}
                onClick={(event) => {
                  event.stopPropagation()
                  openFilePicker()
                }}
              >
                Upload Drawing
              </Button>
            }
          />

          {documents.length > 0 ? (
            <div onClick={(event) => event.stopPropagation()}>
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
            </div>
          ) : (
            <ProjectDocumentList documents={documents} disabled={selecting} />
          )}

          {(rejectedFiles.length > 0 || uploadError || queuedFiles.length > 0) && (
            <div className="mt-4 space-y-3" onClick={(event) => event.stopPropagation()}>
              <AnimatePresence initial={false}>
                {uploadError && (
                  <Alert
                    key="upload-error"
                    tone="danger"
                    title="Upload failed"
                    onDismiss={() => setUploadError(null)}
                  >
                    {uploadError}
                  </Alert>
                )}

                {rejectedFiles.length > 0 && (
                  <Alert
                    key="upload-rejected"
                    tone="warning"
                    title={`${rejectedFiles.length} file(s) could not be added`}
                    icon={TriangleAlert}
                    onDismiss={() => setRejectedFiles([])}
                  >
                    <ul className="mt-1 space-y-1">
                      {rejectedFiles.map((item) => (
                        <li key={item.name} className="text-sm">
                          <span className="font-medium text-white">{item.name}</span> —{' '}
                          {item.reason}
                        </li>
                      ))}
                    </ul>
                  </Alert>
                )}
              </AnimatePresence>

              {queuedFiles.length > 0 && (
                <>
                  <ul className="space-y-2">
                    {queuedFiles.map((file, index) => (
                      <li
                        key={`${file.name}-${file.size}-${index}`}
                        className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 px-3 py-2"
                      >
                        <span className="truncate text-sm text-white">{file.name}</span>
                        <IconButton
                          icon={Trash2}
                          label={`Remove ${file.name}`}
                          variant="white"
                          size="sm"
                          disabled={isUploadingDrawing}
                          onClick={() => removeQueuedFile(index)}
                        />
                      </li>
                    ))}
                  </ul>

                  <div className="flex items-center justify-end gap-3">
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={isUploadingDrawing}
                      onClick={() => setQueuedFiles([])}
                    >
                      Clear
                    </Button>
                    <Button
                      size="sm"
                      leftIcon={Sparkles}
                      isLoading={isUploadingDrawing}
                      onClick={uploadQueuedDrawings}
                    >
                      Upload {queuedFiles.length > 1 ? `${queuedFiles.length} files` : 'drawing'}
                    </Button>
                  </div>
                </>
              )}
            </div>
          )}
          </Card>
        </div>
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
        description="The project is removed from your list. Its drawings stay on file."
        confirmLabel="Delete Project"
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
