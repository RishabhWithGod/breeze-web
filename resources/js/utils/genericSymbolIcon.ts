import {
  Box,
  Cable,
  CircuitBoard,
  Lightbulb,
  Plug,
  Radar,
  ToggleLeft,
  Zap,
  type LucideIcon,
} from 'lucide-react'

/**
 * A rough-category icon for a symbol with no real crop and no matching
 * reference icon (`SymbolIcon`/`fallbackIconUrl`) — so a review card never
 * shows a blank tile, only ever a plainer stand-in than a matched icon or a
 * real crop would be. Ordered most-specific-first: a name naming both a
 * switch and a light (e.g. "occupancy sensor light switch") reads as the
 * switch, since that is the part actually being counted.
 */
const KEYWORDS = {
  switch: ['switch', 'dimmer', 'sensor'],
  plug: ['receptacle', 'outlet', 'plug', 'connector'],
  lightbulb: ['light', 'lamp', 'fixture', 'led'],
  box: ['box', 'panel', 'panelboard'],
  circuit: ['breaker', 'disconnect', 'switchgear', 'transformer', 'spd'],
  radar: ['detector', 'alarm', 'smoke'],
  cable: ['conduit', 'wire', 'cable', 'conductor', 'thhn', 'feeder', 'ground', 'awg', 'mc'],
} as const

const ICON_FOR: Record<keyof typeof KEYWORDS, LucideIcon> = {
  switch: ToggleLeft,
  plug: Plug,
  lightbulb: Lightbulb,
  box: Box,
  circuit: CircuitBoard,
  radar: Radar,
  cable: Cable,
}

/** Checked in this order — first category whose keyword appears wins. */
const CATEGORY_ORDER: readonly (keyof typeof KEYWORDS)[] = [
  'switch',
  'plug',
  'radar',
  'circuit',
  'box',
  'lightbulb',
  'cable',
]

export function genericSymbolIcon(name: string): LucideIcon {
  const needle = name.toLowerCase()

  for (const category of CATEGORY_ORDER) {
    if (KEYWORDS[category].some((word) => needle.includes(word))) {
      return ICON_FOR[category]
    }
  }

  return Zap
}
