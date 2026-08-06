import { Head } from '@inertiajs/react'
import { ArrowRight, Briefcase, FileText, Hammer, Sparkles } from 'lucide-react'
import { Badge, ButtonLink, Card, IconBubble, SectionHeading } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'

/** The modules that are built, offered as somewhere useful to go next. */
const LIVE_MODULES = [
  {
    label: 'AI Takeoff',
    description: 'Upload a drawing set and review detected symbols.',
    href: ROUTES.aiTakeoff,
    icon: Sparkles,
  },
  {
    label: 'Estimates',
    description: 'Every client-facing estimate, filterable and sortable.',
    href: ROUTES.estimates,
    icon: FileText,
  },
  {
    label: 'Jobs',
    description: 'Full job management: team, notes, files and activity.',
    href: ROUTES.jobs,
    icon: Briefcase,
  },
] as const

export interface ModulePlaceholderProps {
  module: {
    slug: string
    label: string
    description: string
  }
}

/**
 * Shared screen for drawer modules that are not built yet.
 *
 * Every sidebar entry resolves to a real route, so the navigation matches the
 * reference product and nothing dead-ends in a 404.
 */
export default function ModulePlaceholder({ module }: ModulePlaceholderProps) {
  return (
    <PageTransition>
      <Head title={module.label} />

      <PageHeader
        title={module.label}
        subtitle={module.description}
        breadcrumbs={[{ label: 'Modules' }, { label: module.label }]}
      />

      <Card padding="lg" variant="spotlight" className="overflow-hidden">
        <span
          aria-hidden
          className="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-brand/15 blur-3xl"
        />

        <div className="relative flex flex-col items-start gap-6 lg:flex-row lg:items-center">
          <IconBubble icon={Hammer} tone="brand" size="lg" />

          <div className="min-w-0 flex-1">
            <Badge tone="warning">In development</Badge>
            <h2 className="mt-4 text-2xl font-bold text-white sm:text-3xl">
              {module.label} is on the roadmap
            </h2>
            <p className="mt-3 max-w-2xl text-md text-white/70">
              {module.description} This module is part of the product but has not been
              built in this prototype yet — the four modules below are live and fully
              wired to the database.
            </p>
          </div>
        </div>
      </Card>

      <Card padding="lg" className="mt-6">
        <SectionHeading
          title="Available now"
          subtitle="Jump into one of the modules that is already built"
        />

        <ul className="grid gap-4 lg:grid-cols-3">
          {LIVE_MODULES.map((live) => (
            <li key={live.label}>
              <ButtonLink
                href={live.href}
                variant="secondary"
                fullWidth
                className="h-full flex-col items-start gap-2 p-5 text-left"
              >
                <span className="flex items-center gap-2 text-md font-semibold text-white">
                  <live.icon size={17} aria-hidden className="text-brand" />
                  {live.label}
                </span>
                <span className="text-sm font-normal text-white/65">
                  {live.description}
                </span>
                <span className="mt-1 inline-flex items-center gap-1.5 text-sm font-medium text-brand">
                  Open
                  <ArrowRight size={14} aria-hidden />
                </span>
              </ButtonLink>
            </li>
          ))}
        </ul>
      </Card>
    </PageTransition>
  )
}

ModulePlaceholder.layout = appLayout
