import type { PageDimensions } from '@/types'

/**
 * Turning the engine's page-pixel coordinates into a position on screen — and
 * back again when something is dragged.
 *
 * The same model the review overlay uses, kept here as functions so the spatial
 * viewer cannot quietly drift from it. Two rules hold everything together:
 *
 *   1. A bbox is `[x, y, width, height]` in the AI's own page pixel space. That
 *      space is defined by the engine's reported page size and nothing else.
 *   2. Everything drawn is positioned as a percentage of that page. Percentages
 *      survive zoom, pan and the spatial tilt untouched, because the transform
 *      is applied to the plane the markers sit in, never to the markers' own
 *      coordinates.
 *
 * There is deliberately no fallback page size. A page the engine did not
 * measure cannot be drawn on, and guessing one puts every symbol somewhere
 * plausible and wrong.
 */

export interface PlacedBox {
  /** Percentages of the page, 0–100. */
  readonly leftPct: number
  readonly topPct: number
  readonly widthPct: number
  readonly heightPct: number
}

export function placeOnPage(
  bbox: readonly number[],
  page: PageDimensions | undefined,
): PlacedBox | null {
  if (!page || page.width <= 0 || page.height <= 0) return null

  const [x = 0, y = 0, w = 0, h = 0] = bbox

  return {
    leftPct: (x / page.width) * 100,
    topPct: (y / page.height) * 100,
    widthPct: (w / page.width) * 100,
    heightPct: (h / page.height) * 100,
  }
}

/**
 * A drag, in screen pixels, converted back to the page's own pixels.
 *
 * `plane` is the on-screen size of the untransformed drawing plane, so the
 * ratio is the same one `placeOnPage` used. The result is clamped to the page:
 * the server clamps too, and a marker that visibly sits where the server will
 * not keep it is a lie the moment it is saved.
 */
export function moveBboxBy(
  bbox: readonly number[],
  delta: { x: number; y: number },
  plane: { width: number; height: number },
  page: PageDimensions,
): number[] {
  const [x = 0, y = 0, w = 0, h = 0] = bbox

  const nextX = x + (delta.x / plane.width) * page.width
  const nextY = y + (delta.y / plane.height) * page.height

  return [
    Math.max(0, Math.min(page.width - w, nextX)),
    Math.max(0, Math.min(page.height - h, nextY)),
    w,
    h,
  ]
}

/** Where a click on the plane lands, in the page's own pixels. */
export function pointOnPage(
  fraction: { x: number; y: number },
  page: PageDimensions,
): { x: number; y: number } {
  return {
    x: Math.max(0, Math.min(page.width, fraction.x * page.width)),
    y: Math.max(0, Math.min(page.height, fraction.y * page.height)),
  }
}

/* ------------------------------------------------------------------ camera */

/**
 * How the drawing is being looked at.
 *
 * Every field here is a view transformation and nothing else. None of it is
 * ever stored, and none of it reaches a coordinate: the plane is transformed,
 * and the markers keep their percentage positions on it. That separation is
 * what lets the spatial view be the accurate view seen at an angle rather than
 * a second, approximate rendering of the same drawing.
 */
export interface ThreeDCamera {
  /** 1 = the drawing at its natural width in the viewport. */
  readonly zoom: number
  /** Screen pixels the plane is shifted by. */
  readonly panX: number
  readonly panY: number
  /** Degrees around the plane's own centre. */
  readonly rotation: number
  /** Degrees the plane leans away from the viewer. 0 is flat. */
  readonly tilt: number
}

export const FLAT_CAMERA: ThreeDCamera = {
  zoom: 1,
  panX: 0,
  panY: 0,
  rotation: 0,
  tilt: 0,
}

/** The single transform the plane wears. Nothing else is transformed. */
export function cameraTransform(camera: ThreeDCamera): string {
  return [
    `translate(${camera.panX}px, ${camera.panY}px)`,
    `scale(${camera.zoom})`,
    `rotateX(${camera.tilt}deg)`,
    `rotateZ(${camera.rotation}deg)`,
  ].join(' ')
}

const RADIANS = Math.PI / 180

/**
 * A movement on screen, converted back to a movement on the plane.
 *
 * This is the piece that lets a symbol be dragged while the drawing is rotated
 * and tilted. A drag arrives in screen pixels; the plane those pixels land on
 * has been scaled, leaned and turned, so the same drag means a different
 * movement on the paper depending on how it is being viewed. Undoing the
 * transform is the only way the symbol ends up where the pointer put it.
 *
 * The inverse of `scale · rotateX · rotateZ` for a point on the plane:
 *
 *     u = dx / zoom
 *     v = dy / (zoom · cos tilt)      ← the lean foreshortens the y axis
 *     dxPlane =  u·cos θ + v·sin θ    ← and the turn rotates both back
 *     dyPlane = −u·sin θ + v·cos θ
 *
 * The perspective divide is deliberately not modelled. The viewer uses a long
 * focal length, which makes the projection very nearly orthographic, and what
 * remains is well under a pixel over a drag — while modelling it would need the
 * pointer's distance from the vanishing point, which is not knowable from a
 * delta alone. The server clamps the result to the page regardless.
 */
export function screenDeltaToPlane(
  delta: { x: number; y: number },
  camera: ThreeDCamera,
): { x: number; y: number } {
  const zoom = camera.zoom || 1
  const lean = Math.max(0.1, Math.cos(camera.tilt * RADIANS))
  const turn = camera.rotation * RADIANS

  const u = delta.x / zoom
  const v = delta.y / (zoom * lean)

  return {
    x: u * Math.cos(turn) + v * Math.sin(turn),
    y: -u * Math.sin(turn) + v * Math.cos(turn),
  }
}

/**
 * Where a click landed on the plane, as a fraction of the page.
 *
 * `rect` is the plane's own bounding box on screen — which, for a transform
 * around the element's centre, keeps that centre where the plane's centre is.
 * So the click is measured from the centre, un-transformed by the same inverse
 * as a drag, and turned back into a fraction of the untransformed plane.
 */
export function screenPointToPlaneFraction(
  point: { x: number; y: number },
  rect: { left: number; top: number; width: number; height: number },
  plane: { width: number; height: number },
  camera: ThreeDCamera,
): { x: number; y: number } {
  const fromCentre = {
    x: point.x - (rect.left + rect.width / 2),
    y: point.y - (rect.top + rect.height / 2),
  }

  const onPlane = screenDeltaToPlane(fromCentre, camera)

  return {
    x: 0.5 + onPlane.x / plane.width,
    y: 0.5 + onPlane.y / plane.height,
  }
}
