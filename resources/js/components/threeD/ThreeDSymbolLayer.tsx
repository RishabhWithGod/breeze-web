import { memo } from 'react'
import { motion, type PanInfo } from 'framer-motion'
import { categoryKey, cn, symbolLabel } from '@/utils'
import type { SymbolColor } from '@/utils'
import type { PageDimensions } from '@/types'
import { placeOnPage, screenDeltaToPlane, type ThreeDCamera } from './coordinates'
import type { ThreeDMarker } from './markers'

export interface ThreeDSymbolLayerProps {
  markers: readonly ThreeDMarker[]
  page: PageDimensions
  colors: ReadonlyMap<string, SymbolColor>
  camera: ThreeDCamera
  selectedKey: string | null
  onSelect: (marker: ThreeDMarker | null) => void
  /** Receives the movement already converted to the page's own pixels. */
  onMove: (marker: ThreeDMarker, delta: { x: number; y: number }) => void
  showLabels: boolean
  /** True while the Select tool is active and the takeoff is editable. */
  canDrag: boolean
}

/**
 * The markers, floating above the drawing plane.
 *
 * Each is placed as a percentage of the engine's page size — the same
 * arithmetic the review overlay uses — so zoom, pan, rotation and tilt never
 * touch a coordinate. They transform the plane; the markers keep their place on
 * it.
 *
 * The lift is a `translateZ` inside the tilted plane, which is what gives the
 * spatial view its depth: invisible at zero tilt, and as the plane leans the
 * markers separate from the paper without moving on it.
 */
function Layer({
  markers,
  page,
  colors,
  camera,
  selectedKey,
  onSelect,
  onMove,
  showLabels,
  canDrag,
}: ThreeDSymbolLayerProps) {
  const lift = camera.tilt > 0 ? 8 + camera.tilt * 0.55 : 0

  /*
   * Labels are dropped when they would be unreadable rather than shrunk into
   * illegibility — a plan covered in three-pixel text is worse than a plan with
   * none. The selected symbol always keeps its label.
   */
  const labelsWorthDrawing = showLabels && camera.zoom >= 1.6

  return (
    <>
      {markers.map((marker) => {
        const box = placeOnPage(marker.bbox, page)

        // Unplaceable without a page size, and a marker in a guessed place is
        // worse than no marker. The viewer refuses the whole page above this.
        if (box === null) return null

        const color = colors.get(categoryKey(marker.name))
        const isSelected = selectedKey === marker.key
        const isRejected = marker.status === 'rejected'
        const draggable = canDrag && marker.occurrenceKey !== null

        return (
          <motion.div
            key={marker.key}
            data-three-d-marker
            className={cn('absolute', draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer')}
            style={{
              left: `${box.leftPct}%`,
              top: `${box.topPct}%`,
              width: `${box.widthPct}%`,
              height: `${box.heightPct}%`,
              minWidth: 14,
              minHeight: 14,
              transform: `translateZ(${isSelected ? lift * 2.4 : lift}px)`,
              transformStyle: 'preserve-3d',
            }}
            title={`${marker.name} · page ${marker.page}`}
            drag={draggable}
            dragMomentum={false}
            dragElastic={0}
            dragSnapToOrigin
            onDragEnd={(_event, info: PanInfo) => {
              if (Math.abs(info.offset.x) < 2 && Math.abs(info.offset.y) < 2) return

              // Screen pixels, undone back to the plane's own axes — otherwise a
              // drag on a rotated drawing moves the symbol somewhere else.
              onMove(marker, screenDeltaToPlane(info.offset, camera))
            }}
            onClick={(event) => {
              event.stopPropagation()
              onSelect(isSelected ? null : marker)
            }}
          >
            {/*
              A leg down to the paper, so the marker reads as standing above the
              drawing rather than as a bigger box on it. Only while tilted —
              flat, it would just be a line through the symbol.
            */}
            {lift > 0 && (
              <span
                aria-hidden
                className="absolute bottom-0 left-1/2 w-px -translate-x-1/2 bg-white/45"
                style={{ height: lift, transform: `rotateX(-90deg) translateZ(${lift / 2}px)` }}
              />
            )}

            <div
              className={cn(
                'absolute inset-0 rounded-sm border-2 outline-2 outline-white/90',
                isRejected
                  ? 'border-dashed border-red-700 bg-red-600/25'
                  : 'border-(--tdm-border) bg-(--tdm-fill)',
                isSelected && 'ring-2 ring-navy-900 ring-offset-2 ring-offset-white',
              )}
              style={
                {
                  '--tdm-border': color?.onPaper,
                  '--tdm-fill': color?.paperFill,
                  boxShadow: lift > 0 ? `0 ${Math.round(lift / 2)}px ${lift}px rgba(0,0,0,0.4)` : undefined,
                } as React.CSSProperties
              }
            />

            {/* A manual symbol says so — it was not the engine's call. */}
            {marker.origin === 'manual' && (
              <span
                aria-hidden
                className="absolute -top-1 -left-1 size-2 rounded-full bg-white ring-2 ring-navy-900"
              />
            )}

            {(labelsWorthDrawing || (showLabels && isSelected)) && (
              /*
                Counter-rotated, so a label on a turned drawing is still read
                left to right. It is text about the drawing, not part of it.
              */
              <span
                className="pointer-events-none absolute -top-5 left-1/2 -translate-x-1/2 rounded-full bg-navy-900/95 px-1.5 py-0.5 text-[9px] whitespace-nowrap text-white"
                style={{ transform: `translateX(-50%) rotateZ(${-camera.rotation}deg)` }}
              >
                {symbolLabel(marker.name)}
              </span>
            )}
          </motion.div>
        )
      })}
    </>
  )
}

/**
 * Memoised: a drawing can carry hundreds of markers on one page, and panning or
 * turning the plane must not re-run all of them. Only a change of markers,
 * camera, colours or selection does.
 */
export const ThreeDSymbolLayer = memo(Layer)
