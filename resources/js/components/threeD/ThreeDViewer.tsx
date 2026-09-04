import { useEffect, useRef, useState } from 'react'
import { AlertTriangle, ImageOff } from 'lucide-react'
import { Alert, Loader } from '@/components/common'
import { routeTo } from '@/constants'
import type { PageDimensions } from '@/types'
import type { SymbolColor } from '@/utils'
import { ThreeDSymbolLayer } from './ThreeDSymbolLayer'
import {
  cameraTransform,
  screenPointToPlaneFraction,
  type ThreeDCamera,
} from './coordinates'
import type { ThreeDMarker } from './markers'
import type { ThreeDTool } from './ThreeDToolbar'

/**
 * A long focal length, which makes the projection very nearly orthographic.
 *
 * That is deliberate rather than cosmetic: it is what keeps the inverse
 * transform in `screenDeltaToPlane` accurate to well under a pixel, so a symbol
 * dragged on a tilted drawing lands where the pointer put it.
 */
const PERSPECTIVE_PX = 2600

export interface ThreeDViewerProps {
  resultId: number
  page: number
  /** Absent when the engine never reported this page's size. */
  dimensions: PageDimensions | undefined
  markers: readonly ThreeDMarker[]
  colors: ReadonlyMap<string, SymbolColor>
  camera: ThreeDCamera
  onCameraChange: (camera: ThreeDCamera) => void
  tool: ThreeDTool
  selectedKey: string | null
  onSelect: (marker: ThreeDMarker | null) => void
  /** Receives the movement already in the page's own pixels. */
  onMove: (marker: ThreeDMarker, delta: { x: number; y: number }) => void
  /** Null unless the reviewer is placing a symbol; receives a page fraction. */
  onPlace: ((fraction: { x: number; y: number }) => void) | null
  showDrawing: boolean
  showGrid: boolean
  showLabels: boolean
  canEdit: boolean
}

/**
 * The drawing plane and everything on it.
 *
 * One element is transformed — the plane — and everything inside rides along.
 * That is what keeps this honest: markers are positioned as percentages of the
 * engine's page and are never recomputed for the camera, so the spatial view is
 * the accurate view seen at an angle rather than a second rendering of it.
 *
 * A page the engine never measured is refused outright. Placing symbols against
 * a guessed page size produces a drawing that looks right and is wrong, which is
 * the worst of the available outcomes.
 */
export function ThreeDViewer({
  resultId,
  page,
  dimensions,
  markers,
  colors,
  camera,
  onCameraChange,
  tool,
  selectedKey,
  onSelect,
  onMove,
  onPlace,
  showDrawing,
  showGrid,
  showLabels,
  canEdit,
}: ThreeDViewerProps) {
  const [imageStatus, setImageStatus] = useState<'loading' | 'loaded' | 'error'>('loading')
  const viewportRef = useRef<HTMLDivElement>(null)
  const planeRef = useRef<HTMLDivElement>(null)
  const imageRef = useRef<HTMLImageElement>(null)
  /** The camera as the current drag started, plus where the pointer went down. */
  const drag = useRef<{ x: number; y: number; camera: ThreeDCamera } | null>(null)
  /*
   * Whether one is in flight, as state rather than a ref — the plane eases
   * between camera positions, and easing a drag makes it lag the pointer.
   */
  const [dragging, setDragging] = useState(false)

  /*
   * A cached image can finish loading before React attaches `onLoad`, and the
   * event then never fires — the same trap the review overlay documents. So the
   * element is asked directly whenever the page changes.
   */
  useEffect(() => {
    const image = imageRef.current

    if (!image?.complete) {
      setImageStatus('loading')

      return
    }

    setImageStatus(image.naturalWidth > 0 ? 'loaded' : 'error')
  }, [resultId, page])

  if (!dimensions) {
    return (
      <Alert tone="warning" icon={AlertTriangle} title="Spatial view unavailable for this page">
        The AI engine did not report dimensions for page {page}, and a symbol placed against a
        guessed page size would be drawn somewhere plausible and wrong. Nothing is missing from
        the review — open the drawing there to work on this page.
      </Alert>
    )
  }

  const aspect = dimensions.height / dimensions.width

  /**
   * Wheel zoom, kept under the pointer.
   *
   * The point being zoomed towards has to stay where it is, so the pan is
   * corrected by however far that point would otherwise have moved.
   */
  const wheel = (event: React.WheelEvent) => {
    event.preventDefault()

    const viewport = viewportRef.current
    if (!viewport) return

    const next = Math.min(8, Math.max(0.2, camera.zoom * (event.deltaY < 0 ? 1.12 : 1 / 1.12)))
    const ratio = next / camera.zoom

    const rect = viewport.getBoundingClientRect()
    const pointer = {
      x: event.clientX - (rect.left + rect.width / 2),
      y: event.clientY - (rect.top + rect.height / 2),
    }

    onCameraChange({
      ...camera,
      zoom: next,
      panX: pointer.x - (pointer.x - camera.panX) * ratio,
      panY: pointer.y - (pointer.y - camera.panY) * ratio,
    })
  }

  /** Pan and rotate are camera changes. Neither ever reaches a coordinate. */
  const pointerDown = (event: React.PointerEvent) => {
    if (tool === 'select') return
    if ((event.target as HTMLElement).closest('[data-three-d-marker]')) return

    drag.current = { x: event.clientX, y: event.clientY, camera }
    setDragging(true)
    ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  }

  const pointerMove = (event: React.PointerEvent) => {
    const start = drag.current
    if (!start) return

    if (tool === 'pan') {
      onCameraChange({
        ...start.camera,
        panX: start.camera.panX + (event.clientX - start.x),
        panY: start.camera.panY + (event.clientY - start.y),
      })

      return
    }

    // Rotate: horizontal travel turns the plane, a quarter degree per pixel.
    onCameraChange({
      ...start.camera,
      rotation: Math.max(-180, Math.min(180, start.camera.rotation + (event.clientX - start.x) * 0.25)),
    })
  }

  const endDrag = () => {
    drag.current = null
    setDragging(false)
  }

  return (
    <div
      ref={viewportRef}
      onWheel={wheel}
      onPointerDown={pointerDown}
      onPointerMove={pointerMove}
      onPointerUp={endDrag}
      onPointerCancel={endDrag}
      className="relative size-full overflow-hidden rounded-panel border border-hairline bg-navy-900/60"
      style={{
        perspective: camera.tilt > 0 ? `${PERSPECTIVE_PX}px` : undefined,
        cursor: onPlace ? 'crosshair' : tool === 'pan' ? 'grab' : tool === 'rotate' ? 'ew-resize' : 'default',
        touchAction: 'none',
      }}
    >
      <div
        className="absolute inset-0 grid place-items-center"
        style={{ perspective: camera.tilt > 0 ? `${PERSPECTIVE_PX}px` : undefined }}
      >
        <div
          ref={planeRef}
          onClick={(event) => {
            if (onPlace === null) {
              if (tool === 'select') onSelect(null)

              return
            }

            const plane = planeRef.current
            if (!plane) return

            const rect = plane.getBoundingClientRect()

            onPlace(
              screenPointToPlaneFraction(
                { x: event.clientX, y: event.clientY },
                rect,
                { width: rect.width, height: rect.height },
                camera,
              ),
            )
          }}
          className="relative w-[86%] max-w-full origin-center"
          style={{
            paddingTop: `${aspect * 86}%`,
            transform: cameraTransform(camera),
            transformStyle: 'preserve-3d',
            transition: dragging ? undefined : 'transform 120ms ease-out',
          }}
        >
          {showDrawing && (
            <img
              ref={imageRef}
              src={routeTo.reviewPage(resultId, page)}
              alt={`Drawing page ${page}`}
              draggable={false}
              onLoad={() => setImageStatus('loaded')}
              onError={() => setImageStatus('error')}
              className="absolute inset-0 size-full select-none bg-white object-contain shadow-2xl"
            />
          )}

          {/*
            A reference grid in the page's own proportions, not in screen pixels
            — it is a reading aid for the plan and carries no measurements,
            because none were reported.
          */}
          {showGrid && (
            <div
              aria-hidden
              className="pointer-events-none absolute inset-0"
              style={{
                backgroundImage:
                  'linear-gradient(to right, rgba(51,227,255,0.16) 1px, transparent 1px),' +
                  'linear-gradient(to bottom, rgba(51,227,255,0.16) 1px, transparent 1px)',
                backgroundSize: '10% 10%',
              }}
            />
          )}

          <ThreeDSymbolLayer
            markers={markers}
            page={dimensions}
            colors={colors}
            camera={camera}
            selectedKey={selectedKey}
            onSelect={onSelect}
            onMove={onMove}
            showLabels={showLabels}
            canDrag={canEdit && tool === 'select' && onPlace === null}
          />
        </div>
      </div>

      {showDrawing && imageStatus === 'loading' && (
        <div className="pointer-events-none absolute inset-0 grid place-items-center">
          <Loader label="Loading drawing…" />
        </div>
      )}

      {showDrawing && imageStatus === 'error' && (
        <div className="absolute inset-0 grid place-items-center p-6">
          <Alert tone="danger" icon={ImageOff} title="Drawing could not be loaded">
            Page {page} of this drawing could not be rendered. The symbols and their positions
            are unaffected — try again, or open the review screen.
          </Alert>
        </div>
      )}
    </div>
  )
}
