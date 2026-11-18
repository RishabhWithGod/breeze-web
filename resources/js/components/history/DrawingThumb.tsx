import { useMemo } from 'react'

/** Small seeded random numbers, so the same drawing always gets the same thumbnail. */
function rng(seed: string) {
  let h = 2166136261
  for (const ch of seed) h = Math.imul(h ^ ch.charCodeAt(0), 16777619)
  return () => {
    h = Math.imul(h ^ (h >>> 15), 2246822507)
    h = Math.imul(h ^ (h >>> 13), 3266489909)
    return ((h ^= h >>> 16) >>> 0) / 4294967296
  }
}

/**
 * A small blueprint-style floor plan generated from the takeoff's id, as in the Breeze app, so every
 * drawing card looks different. Purely decorative — it is not the drawing itself.
 */
export function DrawingThumb({ seed, marks = 0 }: { seed: string; marks?: number }) {
  const { rooms, dots, runs } = useMemo(() => {
    const r = rng(seed)
    const W = 300
    const H = 150
    const x0 = 26
    const y0 = 22
    const w = W - 52
    const h = H - 44
    const cols = 3 + Math.floor(r() * 3)
    const splitY = y0 + h * (0.45 + r() * 0.15)
    const rooms: [number, number, number, number][] = []
    let x = x0
    for (let i = 0; i < cols; i++) {
      const cw = i === cols - 1 ? x0 + w - x : (w / cols) * (0.7 + r() * 0.6)
      rooms.push([x, y0, cw, splitY - y0])
      x += cw
    }
    const lower = 2 + Math.floor(r() * 3)
    x = x0
    for (let i = 0; i < lower; i++) {
      const cw = i === lower - 1 ? x0 + w - x : (w / lower) * (0.7 + r() * 0.6)
      rooms.push([x, splitY + 10, cw, y0 + h - splitY - 10])
      x += cw
    }
    const n = Math.min(40, Math.max(8, marks))
    const palette = ['#3ee6ff', '#5b82ff', '#4ade80', '#fbbf24', '#f472b6']
    const dots = Array.from({ length: n }, () => ({
      x: x0 + 6 + r() * (w - 12),
      y: y0 + 6 + r() * (h - 12),
      c: palette[Math.floor(r() * palette.length)] ?? '#3ee6ff',
    }))
    const runs = Array.from({ length: 3 }, () => {
      const a = dots[Math.floor(r() * n)]
      const b = dots[Math.floor(r() * n)]
      return a && b ? `M${a.x},${a.y} L${b.x},${a.y} L${b.x},${b.y}` : ''
    })
    return { rooms, dots, runs }
  }, [seed, marks])

  return (
    <svg viewBox="0 0 300 150" preserveAspectRatio="xMidYMid slice" aria-hidden className="absolute inset-0 size-full">
      <defs>
        <pattern id={`g-${seed}`} width="10" height="10" patternUnits="userSpaceOnUse">
          <path d="M10 0H0V10" fill="none" stroke="rgba(126,170,255,.10)" strokeWidth=".6" />
        </pattern>
        <radialGradient id={`v-${seed}`} cx=".75" cy="0" r="1">
          <stop offset="0" stopColor="rgba(62,230,255,.18)" />
          <stop offset="1" stopColor="rgba(62,230,255,0)" />
        </radialGradient>
      </defs>
      <rect width="300" height="150" fill={`url(#g-${seed})`} />
      <rect width="300" height="150" fill={`url(#v-${seed})`} />
      {rooms.map(([x, y, w, h], i) => (
        <rect key={i} x={x} y={y} width={w} height={h} fill="rgba(126,170,255,.04)" stroke="rgba(200,220,255,.55)" strokeWidth="1.1" />
      ))}
      {runs.map((d, i) => (
        <path key={i} d={d} fill="none" stroke="#3ee6ff" strokeOpacity=".55" strokeWidth="1" strokeDasharray="3 3" />
      ))}
      {dots.map((d, i) => (
        <circle key={i} cx={d.x} cy={d.y} r="2.2" fill={d.c} opacity=".95" />
      ))}
    </svg>
  )
}
