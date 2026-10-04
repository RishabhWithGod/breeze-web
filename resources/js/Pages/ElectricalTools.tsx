import { Head } from '@inertiajs/react'
import { ExternalLink, FileText, FolderOpen } from 'lucide-react'
import { useState } from 'react'
import { ButtonLink, Card, IconBubble, SelectField } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ELECTRICAL_TOOLS, routeTo } from '@/constants'

export interface ElectricalToolsProps {
  projects: readonly { id: number; name: string }[]
}

/**
 * A preview of the electrical calculation tools, ahead of the real engine —
 * see `ELECTRICAL_TOOLS` for the catalog itself. Only the project list is real.
 */
export default function ElectricalTools({ projects }: ElectricalToolsProps) {
  const projectOptions = [
    { label: 'Select a project...', value: '' },
    ...projects.map((project) => ({ label: project.name, value: String(project.id) })),
  ]

  // Mirrors the reference screen: the first and third cards start on a project, the rest start blank.
  const [selectedProjects, setSelectedProjects] = useState<Record<string, string>>({
    [ELECTRICAL_TOOLS[0]!.slug]: projects[0] ? String(projects[0].id) : '',
    [ELECTRICAL_TOOLS[2]!.slug]: projects[1] ? String(projects[1].id) : '',
  })

  return (
    <PageTransition>
      <Head title="Electrical Tools" />

      <PageHeader
        title="Electrical Tools"
        subtitle="Professional electrical calculation tools to support accurate, code-compliant estimates and design."
      />

      <div className="grid gap-6 md:grid-cols-2">
        {ELECTRICAL_TOOLS.map((tool) => (
          <Card key={tool.slug} padding="lg" className="flex flex-col">
            <div className="flex items-start gap-4">
              <IconBubble icon={tool.icon} tone="brand" size="lg" />
              <div className="min-w-0">
                <h3 className="text-lg font-semibold text-white">{tool.name}</h3>
                <p className="mt-1.5 text-md text-white/80">{tool.description}</p>
              </div>
            </div>

            <div className="mt-6 flex flex-1 flex-wrap items-end justify-between gap-4">
              <span className="flex items-center gap-2 text-sm text-white/80">
                <FileText size={16} aria-hidden />
                <strong className="text-white">{tool.savedCalculations}</strong> Saved Calculations
              </span>

              <div className="flex items-center gap-2">
                <span className="flex items-center gap-1.5 text-sm text-white/80">
                  <FolderOpen size={16} aria-hidden />
                  Project Context
                </span>
                <SelectField
                  aria-label={`Project context for ${tool.name}`}
                  className="w-48"
                  options={projectOptions}
                  value={selectedProjects[tool.slug] ?? ''}
                  onChange={(event) =>
                    setSelectedProjects((current) => ({ ...current, [tool.slug]: event.target.value }))
                  }
                />
              </div>
            </div>

            <ButtonLink
              href={routeTo.electricalTool(tool.slug)}
              variant="primary"
              leftIcon={ExternalLink}
              fullWidth
              className="mt-6"
            >
              Open Tool
            </ButtonLink>
          </Card>
        ))}
      </div>
    </PageTransition>
  )
}

ElectricalTools.layout = appLayout
