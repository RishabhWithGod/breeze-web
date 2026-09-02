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
 * start. Picking "New Project" leaves the queued files behind and hands the
 * user to the deliberate project-creation screen instead of guessing a name
 * from the first file, like the upload flow used to.
 */
export function ProjectPickerCard({
  projects,
  value,
  onChange,
  error,
  index,
}: ProjectPickerCardProps) {
  const options = [
    { value: '', label: projects.length > 0 ? 'Select a client…' : 'No clients yet' },
    ...projects.map((project) => ({
      value: String(project.id),
      label: project.name,
    })),
  ]

  return (
    <Card {...(index !== undefined ? { index } : {})}>
      <CardHeader
        title="Client"
        subtitle="Choose which client this takeoff belongs to"
        actions={
          <ButtonLink href={ROUTES.projectCreate} variant="secondary" size="sm" leftIcon={Plus}>
            New Client
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
