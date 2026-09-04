import {
  Box,
  Hand,
  Maximize2,
  Minus,
  MousePointer2,
  Plus,
  RotateCcw,
  RotateCw,
  Square,
} from 'lucide-react'
import { Button, IconButton, SelectField } from '@/components/common'
import { cn } from '@/utils'

/** Only one of these is active at a time, so a drag never means two things. */
export type ThreeDTool = 'select' | 'pan' | 'rotate'

export interface ThreeDToolbarProps {
  mode: 'flat' | 'spatial'
  onModeChange: (mode: 'flat' | 'spatial') => void
  tool: ThreeDTool
  onToolChange: (tool: ThreeDTool) => void
  zoom: number
  onZoomIn: () => void
  onZoomOut: () => void
  onFit: () => void
  onReset: () => void
  rotation: number
  onRotationChange: (degrees: number) => void
  tilt: number
  onTiltChange: (degrees: number) => void
  page: number
  pageCount: number
  onPageChange: (page: number) => void
}

/**
 * The viewer's controls, on one line.
 *
 * "2D" is first and is where the viewer starts, because it is the honest view:
 * the plan drawn flat, at the coordinates the engine reported. Spatial leans the
 * same plane — it re-projects nothing — so one click always gets back to the
 * accurate drawing.
 *
 * Rotation and tilt only appear in spatial mode. A flat plan that has been
 * turned is a flat plan you have to think about, and there is nothing to gain
 * from it.
 */
export function ThreeDToolbar({
  mode,
  onModeChange,
  tool,
  onToolChange,
  zoom,
  onZoomIn,
  onZoomOut,
  onFit,
  onReset,
  rotation,
  onRotationChange,
  tilt,
  onTiltChange,
  page,
  pageCount,
  onPageChange,
}: ThreeDToolbarProps) {
  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
      {/* ------------------------------------------------------- view ---- */}
      <Group>
        <Pill active={mode === 'flat'} icon={Square} onClick={() => onModeChange('flat')}>
          2D
        </Pill>
        <Pill active={mode === 'spatial'} icon={Box} onClick={() => onModeChange('spatial')}>
          Spatial
        </Pill>
      </Group>

      {/* ------------------------------------------------------- tool ---- */}
      <Group>
        <Pill active={tool === 'select'} icon={MousePointer2} onClick={() => onToolChange('select')}>
          Select
        </Pill>
        <Pill active={tool === 'pan'} icon={Hand} onClick={() => onToolChange('pan')}>
          Pan
        </Pill>
        {mode === 'spatial' && (
          <Pill active={tool === 'rotate'} icon={RotateCw} onClick={() => onToolChange('rotate')}>
            Rotate
          </Pill>
        )}
      </Group>

      {/* ------------------------------------------------------- zoom ---- */}
      <div className="flex items-center gap-1">
        <IconButton icon={Minus} label="Zoom out" variant="white" size="sm" onClick={onZoomOut} />
        <span className="min-w-13 text-center text-sm tabular-nums text-white/90">
          {Math.round(zoom * 100)}%
        </span>
        <IconButton icon={Plus} label="Zoom in" variant="white" size="sm" onClick={onZoomIn} />
      </div>

      <Button size="sm" variant="white" leftIcon={Maximize2} onClick={onFit}>
        Fit
      </Button>
      <Button size="sm" variant="white" leftIcon={RotateCcw} onClick={onReset}>
        Reset
      </Button>

      {mode === 'spatial' && (
        <>
          <Slider
            label="Rotate"
            value={rotation}
            min={-180}
            max={180}
            suffix="°"
            onChange={onRotationChange}
          />
          <Slider label="Tilt" value={tilt} min={0} max={60} suffix="°" onChange={onTiltChange} />
        </>
      )}

      <div className="ml-auto">
        <SelectField
          id="three-d-page"
          aria-label="Drawing page"
          className="w-36"
          value={String(page)}
          onChange={(event) => onPageChange(Number(event.target.value))}
          options={Array.from({ length: Math.max(1, pageCount) }, (_, index) => ({
            value: String(index + 1),
            label: `Page ${index + 1}`,
          }))}
        />
      </div>
    </div>
  )
}

function Group({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex items-center gap-0.5 rounded-panel bg-navy-900/60 p-0.5">{children}</div>
  )
}

function Pill({
  active,
  icon: Icon,
  onClick,
  children,
}: {
  active: boolean
  icon: typeof Box
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'inline-flex items-center gap-1.5 rounded-panel px-2.5 py-1.5 text-sm transition-colors',
        active ? 'bg-brand text-brand-ink' : 'text-white/75 hover:bg-white/10 hover:text-white',
      )}
    >
      <Icon size={14} aria-hidden />
      {children}
    </button>
  )
}

function Slider({
  label,
  value,
  min,
  max,
  suffix,
  onChange,
}: {
  label: string
  value: number
  min: number
  max: number
  suffix: string
  onChange: (value: number) => void
}) {
  return (
    <label className="flex items-center gap-2 text-sm text-white/80">
      {label}
      <input
        type="range"
        min={min}
        max={max}
        step={1}
        value={value}
        onChange={(event) => onChange(Number(event.target.value))}
        className="w-24 accent-brand"
        aria-label={`${label} in degrees`}
      />
      <span className="w-9 text-right tabular-nums text-white/70">
        {value}
        {suffix}
      </span>
    </label>
  )
}
