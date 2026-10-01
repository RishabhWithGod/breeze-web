import { Head, Link, router, usePage } from '@inertiajs/react'
import { ArrowLeft, Building2, UploadCloud } from 'lucide-react'
import { Alert, ButtonLink, Card, CardHeader, SelectField } from '@/components/common'
import { AddendumSelectionList } from '@/components/estimates'
import type { AddendumSummary } from '@/components/estimates'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps } from '@/types'

interface AddendumProject {
  readonly id: number
  readonly clientId: number | null
  readonly name: string
  readonly clientName: string | null
}

interface OriginalEstimate extends AddendumSummary {
  readonly addenda: readonly AddendumSummary[]
}

export interface AddendumIndexProps {
  projects: readonly AddendumProject[]
  selectedProjectId: number | null
  originals: readonly OriginalEstimate[]
}

/**
 * Addendum management: pick a project, and see its original estimate and
 * every addendum raised against it, each with its own total and status.
 *
 * Addenda are added elsewhere (the "Upload Addendum" button, which is the
 * same PDF → AI takeoff → review flow every estimate goes through), not on
 * this screen.
 */
export default function AddendumIndex({
  projects,
  selectedProjectId,
  originals,
}: AddendumIndexProps) {
  const { flash } = usePage<SharedPageProps>().props

  const changeProject = (projectId: string) => {
    router.get(projectId ? routeTo.addendaForProject(Number(projectId)) : ROUTES.addenda)
  }

  return (
    <PageTransition>
      <Head title="Addendum" />

      <Card padding="none" className="overflow-hidden">
        <div className="p-5 sm:p-8">
          <Link
            href={ROUTES.estimates}
            className="inline-flex items-center gap-2 text-md font-medium text-white transition-colors hover:text-brand"
          >
            <ArrowLeft size={17} aria-hidden />
            Estimates
          </Link>
          <h1 className="mt-3 text-3xl font-bold text-white">Addendum</h1>
          <p className="mt-1 text-md text-white/85">
            Manage a project's addenda, and raise a job from a selection of them.
          </p>

          {flash.success && (
            <Alert tone="success" className="mt-6">
              {flash.success}
            </Alert>
          )}
          {flash.warning && (
            <Alert tone="warning" className="mt-6">
              {flash.warning}
            </Alert>
          )}

          <Card padding="md" className="mt-6 max-w-2xl">
            <div className="mb-3 flex items-center gap-3">
              <Building2 size={22} aria-hidden className="shrink-0 text-white/90" />
              <h2 className="text-md font-semibold text-white">Project</h2>
            </div>
            <SelectField
              id="addendum-project"
              aria-label="Project"
              options={[
                { value: '', label: projects.length > 0 ? 'Select a project…' : 'No projects yet' },
                ...projects.map((project) => ({
                  value: String(project.id),
                  label: project.clientName ? `${project.clientName} — ${project.name}` : project.name,
                })),
              ]}
              value={selectedProjectId === null ? '' : String(selectedProjectId)}
              onChange={(event) => changeProject(event.target.value)}
            />
          </Card>

          {selectedProjectId !== null && originals.length === 0 && (
            <Alert tone="info" className="mt-6">
              This project has no standalone estimate yet — run a takeoff for it first, then
              addenda can be raised against the estimate it produces.
            </Alert>
          )}

          <div className="mt-6 flex flex-col gap-4">
            {originals.map((original) => (
              <OriginalCard key={original.id} original={original} />
            ))}
          </div>
        </div>
      </Card>
    </PageTransition>
  )
}

AddendumIndex.layout = appLayout

function OriginalCard({ original }: { original: OriginalEstimate }) {
  return (
    <Card padding="md">
      <CardHeader
        title={`Original Estimate — ${original.number}`}
        subtitle={`${original.addenda.length} addendum${original.addenda.length === 1 ? '' : 's'} on record`}
        actions={
          <ButtonLink href={routeTo.uploadAddendumFor(original.id)} leftIcon={UploadCloud}>
            Upload Addendum
          </ButtonLink>
        }
      />

      <AddendumSelectionList
        original={{ id: original.id, number: original.number, amount: original.amount, status: original.status }}
        addenda={original.addenda}
        selected={new Set()}
        onToggle={() => {}}
        selectable={false}
      />
    </Card>
  )
}
