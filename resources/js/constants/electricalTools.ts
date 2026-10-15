import { Calculator, CircuitBoard, Layers, Zap, type LucideIcon } from 'lucide-react'

/**
 * The electrical calculation tools shown on the Electrical Tools screen.
 *
 * A preview ahead of the real engine: `savedCalculations` is illustrative, not
 * read from the database — see `ElectricalToolsController`.
 */
export interface ElectricalToolDefinition {
  readonly slug: string
  readonly name: string
  readonly description: string
  readonly icon: LucideIcon
  readonly savedCalculations: number
}

export const ELECTRICAL_TOOLS: readonly ElectricalToolDefinition[] = [
  {
    slug: 'circuit-colors',
    name: 'Circuit Colors & Panel Mapping',
    description:
      'Generate wire color schedules and create panel mapping with load assignments. Built to NEC standards for fast, accurate documentation.',
    icon: CircuitBoard,
    savedCalculations: 12,
  },
  {
    slug: 'conduit-fill',
    name: 'Conduit Fill',
    description:
      'Calculate conduit fill based on NEC Chapter 9, Table 1 with support for multiple conductors, raceway types, and fill percentages.',
    icon: Layers,
    savedCalculations: 8,
  },
  {
    slug: 'voltage-drop',
    name: 'Voltage Drop',
    description:
      'Calculate voltage drop for branch circuits and feeders using NEC approved methods. Supports copper, aluminum, single and three phase systems.',
    icon: Zap,
    savedCalculations: 15,
  },
  {
    slug: 'measurement-calculator',
    name: 'Measurement Calculator',
    description:
      'Quickly convert and calculate common electrical measurements including length, area, volume, and wire pulls. Built for field and estimating use.',
    icon: Calculator,
    savedCalculations: 6,
  },
] as const

export function findElectricalTool(slug: string): ElectricalToolDefinition | undefined {
  return ELECTRICAL_TOOLS.find((tool) => tool.slug === slug)
}
