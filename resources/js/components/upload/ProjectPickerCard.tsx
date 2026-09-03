import { Plus } from 'lucide-react'
import { ButtonLink, Card, CardHeader, SelectField } from '@/components/common'
import { ROUTES } from '@/constants'
import type { UploadTargetProject } from '@/types'

export interface ProjectPickerCardProps {
  projects: readonly UploadTargetProject[]
  value: number | null
  onChange: (projectId: number | null) => void
  error?: string
  index?: number
}

/**
 * Which project this takeoff run belongs to — required before a run can
 * start. A drawing is taken off a project, not off a client: a client with
 * four projects has four sets of drawings.
 *
 * "New Project" leaves the queued files behind and hands the user to the
 * deliberate creation screen instead of guessing a name from the first file,
 * like the upload flow used to.
 */
export function ProjectPickerCard({
  projects,
  value,
  onChange,
  error,
  index,
}: ProjectPickerCardProps) {
  /*
   * Named with its client, because a project's name only means something
   * beside it — two clients can both have a "Phase 2", and the person
   * uploading has to be able to tell them apart.
   */
  const options = [
    { value: '', label: projects.length > 0 ? 'Select a project…' : 'No projects yet' },
    ...projects.map((project) => ({
      value: String(project.id),
      label: project.clientName
        ? `${project.clientName} — ${project.name}`
        : project.name,
    })),
  ]

  return (
    <Card {...(index !== undefined ? { index } : {})}>
      <CardHeader
        title="Project"
        subtitle="Which project's drawing this is"
        actions={
          <ButtonLink href={ROUTES.projectCreate} variant="secondary" size="sm" leftIcon={Plus}>
            New Project
          </ButtonLink>
        }
      />

      <SelectField
        id="upload-project"
        options={options}
        value={value === null ? '' : String(value)}
        onChange={(event) => onChange(event.target.value ? Number(event.target.value) : null)}
        {...(error ? { error } : {})}
      />
    </Card>
  )
}
