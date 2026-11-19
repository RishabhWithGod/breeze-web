import { Head } from '@inertiajs/react'
import { ArrowRight, FileText, FolderOpen } from 'lucide-react'
import { useState } from 'react'
import { ButtonLink, SelectField } from '@/components/common'
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

      <div className="grid gap-5 md:grid-cols-2">
        {ELECTRICAL_TOOLS.map((tool) => {
          const Icon = tool.icon

          return (
            <section
              key={tool.slug}
              className="glass flex flex-col rounded-card border border-hairline p-[22px] transition-[transform,border-color] duration-150 hover:-translate-y-0.5 hover:border-brand/45"
            >
              <div className="flex items-start gap-4">
                <span className="grid size-[54px] shrink-0 place-items-center rounded-[14px] bg-[var(--brand-bg)] text-brand">
                  <Icon size={26} aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                  <h3 className="font-display text-xl leading-[1.25] font-[620] tracking-[-0.01em] text-white">{tool.name}</h3>
                  <p className="mt-1.5 text-[14px] text-[var(--text-2)]">{tool.description}</p>
                  <p className="mt-2.5 flex items-center gap-1.5 text-[13px] text-[var(--text-3)]">
                    <FileText size={14} aria-hidden />
                    <strong className="font-semibold text-white">{tool.savedCalculations}</strong> saved calculations
                  </p>
                </div>
              </div>

              <div className="mt-5 flex flex-1 flex-wrap items-end justify-between gap-3 border-t border-hairline pt-4">
                <div className="flex items-center gap-2">
                  <span className="flex items-center gap-1.5 text-[13px] text-[var(--text-3)]">
                    <FolderOpen size={15} aria-hidden />
                    Project context
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

                <ButtonLink href={routeTo.electricalTool(tool.slug)} variant="primary" rightIcon={ArrowRight}>
                  Open tool
                </ButtonLink>
              </div>
            </section>
          )
        })}
      </div>
    </PageTransition>
  )
}

ElectricalTools.layout = appLayout
