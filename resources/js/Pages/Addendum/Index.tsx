import { Head, router, usePage } from '@inertiajs/react'
import { UploadCloud } from 'lucide-react'
import { Alert, ButtonLink, Card, CardHeader, SelectField } from '@/components/common'
import { CreateJobFromEstimatesCard } from '@/components/estimates'
import type { AddendumSummary } from '@/components/estimates'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { ClientOption, SharedPageProps } from '@/types'

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
  clients: readonly ClientOption[]
  teams: readonly { readonly id: number; readonly name: string }[]
}

/**
 * Addendum management: pick a project, see its original estimate and every
 * addendum raised against it, choose which ones belong in a job, and raise
 * one from exactly that selection.
 *
 * Selecting here never changes an estimate's own total — it only decides
 * what a job built from this screen combines. Addenda are added elsewhere
 * (the "Upload Addendum" button, which is the same PDF → AI takeoff → review
 * flow every estimate goes through), not on this screen.
 */
export default function AddendumIndex({
  projects,
  selectedProjectId,
  originals,
  clients,
  teams,
}: AddendumIndexProps) {
  const { flash } = usePage<SharedPageProps>().props

  const changeProject = (projectId: string) => {
    router.get(projectId ? routeTo.addendaForProject(Number(projectId)) : ROUTES.addenda)
  }

  return (
    <PageTransition>
      <Head title="Addendum" />

      <PageHeader
        title="Addendum"
        subtitle="Manage a project's addenda, and raise a job from a selection of them"
        breadcrumbs={[{ label: 'Estimates', href: ROUTES.estimates }, { label: 'Addendum' }]}
      />

      {flash.success && (
        <Alert tone="success" className="mb-6">
          {flash.success}
        </Alert>
      )}
      {flash.warning && (
        <Alert tone="warning" className="mb-6">
          {flash.warning}
        </Alert>
      )}

      <Card padding="lg" className="mb-6">
        <SelectField
          id="addendum-project"
          label="Project"
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
        <Alert tone="info">
          This project has no standalone estimate yet — run a takeoff for it first, then
          addenda can be raised against the estimate it produces.
        </Alert>
      )}

      <div className="flex flex-col gap-6">
        {originals.map((original) => (
          <OriginalCard
            key={original.id}
            original={original}
            project={projects.find((p) => p.id === selectedProjectId) ?? null}
            clients={clients}
            teams={teams}
          />
        ))}
      </div>
    </PageTransition>
  )
}

AddendumIndex.layout = appLayout

function OriginalCard({
  original,
  project,
  clients,
  teams,
}: {
  original: OriginalEstimate
  project: AddendumProject | null
  clients: readonly ClientOption[]
  teams: readonly { readonly id: number; readonly name: string }[]
}) {
  return (
    <Card padding="lg">
      <CardHeader
        title={`Original Estimate — ${original.number}`}
        subtitle={`${original.addenda.length} addendum${original.addenda.length === 1 ? '' : 's'} on record`}
        actions={
          <ButtonLink href={routeTo.uploadAddendumFor(original.id)} size="sm" leftIcon={UploadCloud}>
            Upload Addendum
          </ButtonLink>
        }
      />

      <CreateJobFromEstimatesCard
        original={{ id: original.id, number: original.number, amount: original.amount, status: original.status }}
        addenda={original.addenda}
        clientId={project?.clientId ?? null}
        clients={clients}
        teams={teams}
      />
    </Card>
  )
}
