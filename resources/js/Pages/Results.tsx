import { useState } from 'react'
import { Head } from '@inertiajs/react'
import {
  BadgeCheck,
  Clock4,
  Download,
  FileSpreadsheet,
  Layers3,
  Share2,
  Zap,
} from 'lucide-react'
import { Button, ButtonLink, Card, CardHeader } from '@/components/common'
import { ActivityFeed, StatCard } from '@/components/dashboard'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  DrawingPreview,
  ProjectSummaryCard,
  SheetList,
  SymbolLegendTable,
} from '@/components/results'
import { ROUTES } from '@/constants'
import type { DrawingSheet, TakeoffProject } from '@/types'
import { formatNumber } from '@/utils'

const METRIC_ICONS = [Zap, FileSpreadsheet, Clock4, BadgeCheck] as const

export interface ResultsProps {
  project: TakeoffProject
}

/** Read-only results dashboard for one completed takeoff. */
export default function Results({ project }: ResultsProps) {
  const [activeSheet, setActiveSheet] = useState<DrawingSheet | undefined>(
    project.sheets[1] ?? project.sheets[0],
  )

  const totalSymbols = project.symbols.reduce((total, symbol) => total + symbol.count, 0)
  const lowConfidence = project.symbols.filter((symbol) => symbol.confidence < 0.75).length

  return (
    <PageTransition>
      <Head title={`${project.name} — Results`} />

      <PageHeader
        title="Takeoff Results"
        subtitle="Review detected symbols, quantities and confidence before converting to an estimate."
        breadcrumbs={[{ label: 'AI Takeoff', href: ROUTES.upload }, { label: 'Results' }]}
        actions={
          <>
            <Button variant="secondary" leftIcon={Share2}>
              Share
            </Button>
            <Button variant="secondary" leftIcon={Download}>
              Export
            </Button>
            <ButtonLink href={ROUTES.upload} variant="dark" leftIcon={Layers3}>
              New takeoff
            </ButtonLink>
          </>
        }
      />

      <div className="space-y-6">
        <ProjectSummaryCard project={project} />

        {/* Summary metrics */}
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
          {project.metrics.map((metric, index) => (
            <StatCard
              key={metric.id}
              label={metric.label}
              value={metric.value}
              {...(metric.delta ? { delta: metric.delta } : {})}
              {...(metric.trend ? { trend: metric.trend } : {})}
              {...(metric.hint ? { hint: metric.hint } : {})}
              icon={METRIC_ICONS[index % METRIC_ICONS.length]}
              index={index}
            />
          ))}
        </div>

        {/* Legend table */}
        <Card padding="lg">
          <CardHeader
            title="Detected Symbols"
            subtitle={`${formatNumber(totalSymbols)} devices across ${project.symbols.length} classes · ${lowConfidence} flagged for review`}
          />
          <SymbolLegendTable symbols={project.symbols} />
        </Card>

        {/* Sheets + preview + activity */}
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
          <div className="flex flex-col gap-6">
            <Card padding="lg">
              <CardHeader
                title="Drawing Sheets"
                subtitle={`${project.sheets.length} sheets analysed`}
              />
              <SheetList
                sheets={project.sheets}
                {...(activeSheet ? { activeId: activeSheet.id } : {})}
                onSelect={setActiveSheet}
              />
            </Card>

            <Card padding="lg">
              <CardHeader title="Recent Activity" subtitle="Pipeline event log" />
              <ActivityFeed entries={project.activity} />
            </Card>
          </div>

          <Card padding="lg">
            <CardHeader
              title="Sheet Preview"
              subtitle="Detected symbol positions are approximate in this prototype"
            />
            {activeSheet && (
              <DrawingPreview
                sheetCode={activeSheet.code}
                title={activeSheet.title}
                scale={activeSheet.scale}
              />
            )}

            <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
              {[
                {
                  label: 'Sheet symbols',
                  value: formatNumber(activeSheet?.symbolCount ?? 0),
                },
                { label: 'Pages', value: String(activeSheet?.pageCount ?? 0) },
                { label: 'Scale', value: activeSheet?.scale ?? '—' },
              ].map((item) => (
                <div
                  key={item.label}
                  className="rounded-panel border border-hairline bg-navy-950/30 p-3"
                >
                  <p className="text-xs tracking-wide text-white/70 uppercase">
                    {item.label}
                  </p>
                  <p className="mt-1 truncate text-md font-semibold text-white">
                    {item.value}
                  </p>
                </div>
              ))}
            </div>
          </Card>
        </div>
      </div>
    </PageTransition>
  )
}

Results.layout = appLayout
