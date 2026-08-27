/**
 * Deterministic name → color, shared by every surface that shows a symbol's
 * identity: the drawing marker, the legend, and the drawing's own category
 * filter chips. One color per category, and — unlike a raw hash-to-hue
 * spread, which two real category names (`thermostat` / `junction box`)
 * proved can land within a single degree of each other on the hue wheel —
 * this draws from a small curated palette and walks to the next free slot on
 * collision, so every distinct category in one drawing gets a genuinely
 * different color, not just a different hash.
 *
 * Hues deliberately avoid the app's reserved status colors (success green,
 * danger red, warning amber, info cyan) so a category's identity color is
 * never mistaken for an approval state.
 */

const PALETTE_HUES = [
  50, 60, 70, 80, 90, 100, 130, 140, 150, 160, 200, 210, 220, 230, 240, 250,
  260, 270, 280, 290, 300, 310, 320, 330, 340,
] as const

const GOLDEN_ANGLE = 137.508

function hashString(value: string): number {
  let hash = 0x811c9dc5

  for (let i = 0; i < value.length; i++) {
    hash ^= value.charCodeAt(i)
    hash = Math.imul(hash, 0x01000193)
  }

  return hash >>> 0
}

export interface SymbolColor {
  readonly border: string
  readonly fill: string
  readonly solid: string
}

function colorForHue(hue: number, slot: number): SymbolColor {
  // Alternating saturation/lightness on top of the hue spacing means even
  // two palette neighbors (10° apart) still read as visually distinct.
  const lightness = slot % 2 === 0 ? 62 : 55
  const saturation = slot % 3 === 0 ? 82 : 74

  return {
    border: `hsl(${hue} ${saturation}% ${lightness}%)`,
    fill: `hsl(${hue} ${saturation}% ${lightness}% / 0.20)`,
    solid: `hsl(${hue} ${Math.max(70, saturation - 4)}% ${Math.max(48, lightness - 7)}%)`,
  }
}

function fallbackColor(name: string): SymbolColor {
  const hue = (hashString(name) * GOLDEN_ANGLE) % 360

  return {
    border: `hsl(${hue} 80% 62%)`,
    fill: `hsl(${hue} 80% 62% / 0.20)`,
    solid: `hsl(${hue} 78% 55%)`,
  }
}

/**
 * Assigns every distinct name in `names` a palette color — the same input
 * set always produces the same assignment, and up to `PALETTE_HUES.length`
 * distinct names are guaranteed collision-free. Beyond that (rare — most
 * drawings carry a few dozen symbol types at most) the extra names fall back
 * to unbounded hash-hue spacing, still deterministic but no longer
 * collision-guaranteed.
 */
export function resolveSymbolColors(names: readonly string[]): ReadonlyMap<string, SymbolColor> {
  const distinct = [...new Set(names.map((name) => name.trim().toLowerCase()))].sort()
  const map = new Map<string, SymbolColor>()
  const taken = new Set<number>()

  for (const name of distinct) {
    const paletteLength = PALETTE_HUES.length
    let slot = hashString(name) % paletteLength
    let attempts = 0

    while (taken.has(slot) && attempts < paletteLength) {
      slot = (slot + 1) % paletteLength
      attempts++
    }

    const hue = PALETTE_HUES[slot]

    if (attempts < paletteLength && hue !== undefined) {
      taken.add(slot)
      map.set(name, colorForHue(hue, slot))
    } else {
      map.set(name, fallbackColor(name))
    }
  }

  return map
}

/**
 * A single name with no sibling list available. Still deterministic, but —
 * unlike {@link resolveSymbolColors} — cannot guarantee separation from
 * other names it never saw. Prefer `resolveSymbolColors` wherever the full
 * set of names on screen is known.
 */
export function symbolColor(name: string): SymbolColor {
  const key = name.trim().toLowerCase()

  return resolveSymbolColors([key]).get(key) ?? fallbackColor(key)
}

/** Display form for a symbol name — engine names arrive in every case (all-caps, all-lowercase); this normalises to Title Case for reading. */
export function symbolLabel(name: string): string {
  return name.replace(/\S+/g, (word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
}
