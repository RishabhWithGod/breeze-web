import { useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  ArrowLeft,
  Briefcase,
  Building2,
  ClipboardList,
  FileText,
  Files,
  FolderKanban,
  Mail,
  MapPin,
  Package,
  Pencil,
  Phone,
  Receipt,
  Sparkles,
  Trash2,
  Upload as UploadIcon,
  Users,
} from 'lucide-react'
import {
  Alert,
  Badge,
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
  Tone,
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
        {/* -------------------------------------------------- Information ---- */}
        <Card padding="md" className="min-w-0">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <FolderKanban size={18} aria-hidden className="text-white/70" />
                Project Information
              </span>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          <dl className="flex flex-col gap-3">
            <InfoRow
              label="Client"
              icon={Building2}
              value={
                <Link
                  href={routeTo.client(project.clientId)}
                  className="text-brand hover:underline"
                >
                  {project.client}
                </Link>
              }
            />
            <InfoRow label="Site" value={project.location ?? 'Not recorded'} icon={MapPin} />
            <InfoRow
              label="Type"
              icon={FolderKanban}
              value={
                project.projectType
                  ? `${JOB_TYPE_LABEL[project.projectType]} · ${project.discipline}`
                  : project.discipline
              }
            />
            <InfoRow
              label="Commodity List"
              icon={Package}
              value={
                <span title="Commodity lists aren't tracked yet — coming soon">
                  <Badge tone="neutral" size="sm">
                    Not available yet
                  </Badge>
                </span>
              }
            />
          </dl>

          <dl className="mt-6 flex flex-col gap-3 border-t border-hairline pt-6">
            <InfoRow label="Opened" value={formatDate(project.createdAt)} />
          </dl>

          {project.notes && (
            <div className="mt-6 border-t border-hairline pt-6">
              <p className="flex items-center gap-1.5 text-sm text-white/60">
                <FileText size={13} aria-hidden className="shrink-0" />
                Notes
              </p>
              <p className="mt-1 text-md whitespace-pre-line text-white/90">
                {project.notes}
              </p>
            </div>
          )}
        </Card>

        {/* --------------------------------------------------- Drawing PDFs --- */}
        <Card padding="md" className="min-w-0 xl:col-span-2">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <FileText size={18} aria-hidden className="text-white/70" />
                Drawing PDFs
              </span>
            }
            subtitle={
              documents.length > 1
                ? 'Every drawing on record. Pick the one the next takeoff should run against.'
                : 'Every drawing on record for this client.'
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
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

      {/* --------------------------------------------------------- Team ---- */}
      <Card padding="md" className="mt-6">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <Users size={18} aria-hidden className="text-white/70" />
              Team
            </span>
          }
          actions={
            <Link
              href={routeTo.projectEdit(project.id)}
              className="text-sm text-brand hover:underline"
            >
              Manage Team →
            </Link>
          }
          className="border-b border-hairline pb-4"
          titleClassName="text-lg font-semibold"
        />

        {project.members.length === 0 ? (
          <p className="text-md text-white/70">No one staffed to this project yet.</p>
        ) : (
          <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {project.members.map((member) => (
              <li
                key={member.id}
                className="flex items-start gap-3 rounded-panel border border-hairline bg-white/4 p-3"
              >
                <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-brand/20 text-sm font-semibold text-brand">
                  {member.initials}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-md font-medium text-white">
                    {member.name}
                  </span>
                  <span className="block text-sm text-white/60">{member.roleLabel}</span>
                  <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/70">
                    {member.phone && (
                      <span className="flex items-center gap-1.5">
                        <Phone size={12} aria-hidden className="shrink-0" />
                        {member.phone}
                      </span>
                    )}
                    {member.email && (
                      <span className="flex items-center gap-1.5">
                        <Mail size={12} aria-hidden className="shrink-0" />
                        {member.email}
                      </span>
                    )}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {/* ================================================ Project Summary ==== */}
      <Card padding="md" className="mt-6">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <ClipboardList size={18} aria-hidden className="text-white/70" />
              Project Summary
            </span>
          }
          className="border-b border-hairline pb-4"
          titleClassName="text-lg font-semibold"
        />

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
          <SummaryStat icon={FileText} label="Drawings" value={project.drawingCount} />
          <SummaryStat icon={Sparkles} label="Takeoffs" value={project.takeoffCount} tone="brand" />
          <SummaryStat icon={ClipboardList} label="Estimates" value={project.estimateCount} />
          <SummaryStat icon={Briefcase} label="Jobs" value={project.jobCount} />
          <SummaryStat icon={Users} label="Team" value={project.teamCount} />
          <SummaryStat icon={Files} label="Documents" value={project.documentCount} />
          <SummaryStat icon={Receipt} label="Invoices" value={project.invoiceCount} />
        </div>
      </Card>

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

interface InfoRowProps {
  label: string
  value: React.ReactNode | null
  icon?: React.ComponentType<{ size?: number; className?: string; 'aria-hidden'?: boolean }>
}

function InfoRow({ label, value, icon: Icon }: InfoRowProps) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-3">
      <dt className="flex items-center gap-1.5 text-sm text-white/60">
        {Icon && <Icon size={13} aria-hidden className="shrink-0" />}
        {label}
      </dt>
      <dd className="text-md text-white">{value ?? <span className="text-white/45">—</span>}</dd>
    </div>
  )
}

interface SummaryStatProps {
  icon: React.ComponentType<{ size?: number; className?: string; 'aria-hidden'?: boolean }>
  label: string
  value: number
  tone?: Tone
}

function SummaryStat({ icon: Icon, label, value, tone = 'neutral' }: SummaryStatProps) {
  const TONE_TEXT: Record<Tone, string> = {
    brand: 'text-brand',
    success: 'text-status-success',
    warning: 'text-status-warning',
    danger: 'text-red-300',
    info: 'text-status-info',
    neutral: 'text-white',
  }

  return (
    <div className="flex items-center gap-2">
      <Icon size={16} aria-hidden className="shrink-0 text-white/50" />
      <div className="leading-tight">
        <p className={`text-lg font-semibold tabular-nums ${TONE_TEXT[tone]}`}>{value}</p>
        <p className="text-sm text-white/60">{label}</p>
      </div>
    </div>
  )
}

ProjectShow.layout = appLayout
