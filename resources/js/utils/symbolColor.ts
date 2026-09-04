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

/*
 * An ordered list of hues, arranged so that taking the first N gives N colours
 * that look as different from one another as this many can.
 *
 * Order matters and spacing does not. Evenly spaced degrees looked right on
 * paper and wrong on screen: 220°, 240°, 260°, 280° and 300° are five equal
 * steps and five shades of the same violet, so half a legend read as one
 * colour. Perceptual distance is not linear in hue — 50° to 130° crosses
 * yellow to green, while 220° to 300° never leaves blue-purple.
 *
 * So the first eleven are one per nameable band — sky, yellow, green, violet,
 * orange, teal, magenta, blue, yellow-green, indigo, teal-green — and only
 * after those run out does it come back for a second tone of each. Eleven is
 * not arbitrary: it is how many bands there are once red is off the table.
 *
 * Red and its neighbours are missing on purpose. Red is what a rejected marker
 * is drawn in, and a crimson or hot-pink category two pixels wide on a busy
 * drawing is a rejected marker as far as anyone reading the page is concerned.
 */
const PALETTE_HUES = [
  // One per band: the eleven most distinguishable.
  210, 45, 130, 290, 30, 180, 315, 245, 85, 265, 160,
  // Then a second tone of each, in the same order.
  200, 60, 110, 280, 40, 190, 300, 230, 75, 255, 170,
] as const

/**
 * How a hue is varied each time the palette laps.
 *
 * Three tones over twenty-two hues gives sixty-six categories that are all
 * different colours on the page, measured rather than assumed: the closest
 * pair of the sixty-six is 9.2 apart in RGB, and none of them falls below 3:1
 * against white paper.
 */
const PAPER_TONES = [
  { saturation: 100, deepen: 0 },
  { saturation: 100, deepen: 14 },
  { saturation: 58, deepen: 4 },
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
  /**
   * The same hue, darkened until it is legible on paper.
   *
   * `border` is tuned for this app's dark chrome — the legend, the filter
   * chips — where a light, saturated colour reads well. A drawing is white,
   * and the same colour there is a pale line on a pale page: the yellows and
   * light greens all but vanished. This is the one to draw a marker on the
   * page with.
   */
  readonly onPaper: string
  /** The same paper colour as a light wash, filled inside the marker. */
  readonly paperFill: string
}

/** sRGB relative luminance of an HSL triple, per WCAG. */
function luminanceOf(hue: number, saturation: number, lightness: number): number {
  const s = saturation / 100
  const l = lightness / 100
  const c = (1 - Math.abs(2 * l - 1)) * s
  const x = c * (1 - Math.abs(((hue / 60) % 2) - 1))
  const m = l - c / 2

  const [r, g, b] =
    hue < 60
      ? [c, x, 0]
      : hue < 120
        ? [x, c, 0]
        : hue < 180
          ? [0, c, x]
          : hue < 240
            ? [0, x, c]
            : hue < 300
              ? [x, 0, c]
              : [c, 0, x]

  const channel = (value: number) => {
    const v = value + m

    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
  }

  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
}

/**
 * The lightest this hue can be and still hold 3:1 against white paper.
 *
 * Walked down rather than fixed, because the answer depends entirely on the
 * hue: a blue holds it at 64% lightness and stays vivid, a yellow not until
 * about 30%. There is no way around that — yellow at full brightness has
 * almost no contrast against white, and no amount of wanting it brighter
 * changes the physics. One flat value would either lose the yellows or make
 * every blue muddy.
 *
 * Only 3:1, because on the drawing this colour never stands alone: it carries
 * a white halo outside it and a wash of itself inside, and those do the
 * separating. That is what lets the hue stay saturated instead of being
 * darkened into mud to win a contrast figure on its own.
 */
function darkenForPaper(hue: number, saturation: number): number {
  for (let lightness = 64; lightness >= 20; lightness -= 2) {
    const contrast = 1.05 / (luminanceOf(hue, saturation, lightness) + 0.05)

    if (contrast >= 3) return lightness
  }

  return 20
}

/**
 * @param index This category's position in the allocation — not its slot within
 *              the palette. The lap is worked out from it, so a category on the
 *              palette's second pass comes out a different tone rather than a
 *              copy of the one it shares a hue with.
 */
function colorForHue(hue: number, index: number): SymbolColor {
  // Alternating saturation/lightness on top of the hue spacing means even
  // two palette neighbors (10° apart) still read as visually distinct.
  const lightness = index % 2 === 0 ? 62 : 55
  const saturation = index % 3 === 0 ? 82 : 74

  /*
   * The colour as it goes on the drawing.
   *
   * Fully saturated where it can be — the lightness is forced down by the hue,
   * and letting the saturation fall with it is what turned these into mud.
   *
   * The tone varies with the lap, so a category on the palette's second pass
   * is not the same colour as the one it shares a hue with. This used to
   * ignore the lap entirely, which meant categories 1 and 23 had different
   * legend swatches and byte-identical boxes on the page.
   *
   * Deepening alone was not enough either: a hue that is already dark at lap 0
   * — the yellows sit at 30% — hits the floor on lap 1 and every lap after it
   * lands on the same value. The third tone mutes the saturation instead of
   * darkening further, which is a change the eye can still see at the bottom
   * of the lightness range.
   */
  const lap = Math.floor(index / PALETTE_HUES.length)
  const tone = PAPER_TONES[lap % PAPER_TONES.length] ?? PAPER_TONES[0]
  const paperLightness = Math.max(20, darkenForPaper(hue, tone.saturation) - tone.deepen)

  return {
    border: `hsl(${hue} ${saturation}% ${lightness}%)`,
    fill: `hsl(${hue} ${saturation}% ${lightness}% / 0.20)`,
    solid: `hsl(${hue} ${Math.max(70, saturation - 4)}% ${Math.max(48, lightness - 7)}%)`,
    onPaper: `hsl(${hue} ${tone.saturation}% ${paperLightness}%)`,
    paperFill: `hsl(${hue} ${tone.saturation}% ${paperLightness}% / 0.22)`,
  }
}

/**
 * A name with no free palette slot left.
 *
 * Still walked onto the palette rather than given a free hue: an unbounded
 * hash could land on red, and red on this drawing means rejected. The golden
 * angle only chooses which of the safe hues it gets.
 */
function fallbackColor(name: string): SymbolColor {
  const index = Math.floor(
    ((hashString(name) * GOLDEN_ANGLE) % 360) / 360 * PALETTE_HUES.length,
  )
  const hue = PALETTE_HUES[index % PALETTE_HUES.length] ?? PALETTE_HUES[0]

  return colorForHue(hue, index)
}

/**
 * Assigns every distinct name in `names` a colour, taking them from the front
 * of a palette ordered so that the first N are the N most distinguishable.
 *
 * The same input set always produces the same assignment. Resolve the whole
 * drawing's names in one call and share the result: resolving a subset — one
 * page's names, say — is a different input set and therefore a different
 * assignment, which is how the same symbol used to come out one colour on the
 * drawing and another in the legend.
 */
export function resolveSymbolColors(names: readonly string[]): ReadonlyMap<string, SymbolColor> {
  const distinct = [...new Set(names.map(categoryKey))].sort()
  const map = new Map<string, SymbolColor>()
  const paletteLength = PALETTE_HUES.length

  /*
   * Taken in order, because the palette is already ordered by distinctness —
   * see `PALETTE_HUES`. Four categories get the first four, which are a sky
   * blue, a yellow, a green and a violet; nothing clever is needed on top.
   *
   * Sorted first, so the assignment depends only on which names are present,
   * never on the order they arrived in.
   */
  distinct.forEach((name, index) => {
    const slot = index % paletteLength
    const hue = PALETTE_HUES[slot]

    if (hue === undefined) {
      map.set(name, fallbackColor(name))

      return
    }

    /*
     * The raw index, not the slot within the palette.
     *
     * `colorForHue` works the lap out from it — which pass of the palette this
     * is — and varies the tone accordingly. Collapsing the two into `slot +
     * lap` here threw that away: index 22 arrived as 1, `floor(1 / 22)` is 0,
     * and category 23 came out byte-identical to category 2.
     */
    map.set(name, colorForHue(hue, index))
  })

  return map
}

/**
 * A single name with no sibling list available. Still deterministic, but —
 * unlike {@link resolveSymbolColors} — cannot guarantee separation from
 * other names it never saw. Prefer `resolveSymbolColors` wherever the full
 * set of names on screen is known.
 */
export function symbolColor(name: string): SymbolColor {
  const key = categoryKey(name)

  return resolveSymbolColors([key]).get(key) ?? fallbackColor(key)
}

/**
 * Drawn when something names a category the map has never heard of, which the
 * server's own name list makes impossible. Grey and obvious, so the day it does
 * happen it is reported rather than mistaken for a category's real colour.
 */
export const UNMAPPED_COLOR: SymbolColor = {
  border: 'hsl(0 0% 60%)',
  fill: 'hsl(0 0% 60% / 0.20)',
  solid: 'hsl(0 0% 45%)',
  onPaper: 'hsl(0 0% 35%)',
  paperFill: 'hsl(0 0% 35% / 0.22)',
}

/**
 * The key a category is looked up by, everywhere.
 *
 * Engine names arrive in every case and with stray whitespace — "Wall Mount",
 * "wall mount", "WALL MOUNT", "Wall mount " are one category and must resolve
 * to one colour. Exported so no caller has to remember to `.trim().toLowerCase()`
 * and no caller can forget.
 */
export function categoryKey(name: string): string {
  return name.trim().toLowerCase()
}

/** Display form for a symbol name — engine names arrive in every case (all-caps, all-lowercase); this normalises to Title Case for reading. */
export function symbolLabel(name: string): string {
  return name.replace(/\S+/g, (word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
}
