import { useEffect, useImperativeHandle, useMemo, useRef } from 'react'
import { AlertTriangle } from 'lucide-react'
import type { SpatialModel } from './model'
import { SpatialScene, webglAvailable, type SceneState, type SceneSymbols, type ViewPreset } from './scene'

export interface SpatialViewerHandle {
  setView: (view: ViewPreset) => void
  zoom: (factor: number) => void
  focus: (id: string) => void
}

export interface SpatialViewerProps {
  model: SpatialModel
  state: SceneState
  symbols: SceneSymbols
  onSelect: (id: string | null) => void
  ref?: React.Ref<SpatialViewerHandle>
  className?: string
}

/**
 * The 3D view itself: a canvas the scene draws into, and the handles the toolbar above it uses.
 * Where the browser cannot start WebGL it says so rather than leaving a blank box.
 */
export function SpatialViewer({ model, state, symbols, onSelect, ref, className }: SpatialViewerProps) {
  const host = useRef<HTMLDivElement>(null)
  const scene = useRef<SpatialScene | null>(null)
  const supported = useMemo(() => webglAvailable(), [])

  // The scene calls back through a ref, so a new `onSelect` each render never rebuilds it.
  const select = useRef(onSelect)
  useEffect(() => {
    select.current = onSelect
  }, [onSelect])

  useImperativeHandle(ref, () => ({
    setView: (view) => scene.current?.setView(view),
    zoom: (factor) => scene.current?.zoom(factor),
    focus: (id) => scene.current?.focus(id),
  }))

  useEffect(() => {
    const element = host.current
    if (!element) return
    scene.current = new SpatialScene(element, (id) => select.current(id))
    // The model and state are applied by the effects below, which run right after this one.
    return () => {
      scene.current?.dispose()
      scene.current = null
    }
  }, [supported])

  useEffect(() => {
    scene.current?.setModel(model, symbols)
    scene.current?.update(state)
    // A new model starts from the default view; state changes alone must not.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [model, symbols])

  useEffect(() => {
    scene.current?.update(state)
  }, [state])

  if (!supported) {
    return (
      <div className={className}>
        <div className="grid h-full place-items-center p-6 text-center">
          <p className="flex items-center gap-2 text-sm text-white/80">
            <AlertTriangle size={18} aria-hidden className="text-status-warning" />
            This browser could not start the 3D view. Try another browser, or turn on hardware acceleration.
          </p>
        </div>
      </div>
    )
  }

  return <div ref={host} className={className} style={{ touchAction: 'none' }} />
}
