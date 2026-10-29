import * as THREE from 'three'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'
import { CSS2DObject, CSS2DRenderer } from 'three/examples/jsm/renderers/CSS2DRenderer.js'
import { Line2 } from 'three/examples/jsm/lines/Line2.js'
import { LineGeometry } from 'three/examples/jsm/lines/LineGeometry.js'
import { LineMaterial } from 'three/examples/jsm/lines/LineMaterial.js'
import { LineSegments2 } from 'three/examples/jsm/lines/LineSegments2.js'
import { LineSegmentsGeometry } from 'three/examples/jsm/lines/LineSegmentsGeometry.js'
import { type LayerKey, type SpatialModel } from './model'

export type ViewPreset = 'iso' | 'top' | 'front'

/** What the scene needs to know about each symbol of the takeoff: its colour, and how it looks on the drawing. */
export interface SceneSymbol {
  readonly name: string
  /** Any CSS colour. */
  readonly color: string
  /** The symbol's crop from the drawing (or its reference icon); null when there is none. */
  readonly image: string | null
}

export type SceneSymbols = ReadonlyMap<number, SceneSymbol>

export interface SceneState {
  readonly layers: ReadonlySet<LayerKey>
  readonly hiddenCircuits: ReadonlySet<string>
  /** Symbols switched off in the symbol list. */
  readonly hiddenSymbols: ReadonlySet<number>
  /** The symbol's picture, hung over each place it stands. */
  readonly symbolImages: boolean
  /** One tag per symbol, with its picture and name. */
  readonly symbolNames: boolean
  /** The floor plate and the walls around it. */
  readonly context: boolean
  readonly labels: boolean
  /** `c:<component id>` or `r:<route id>`. */
  readonly selected: string | null
}

/** More than this many tags and the model turns to soup; past it only the panel, equipment and the selection are tagged. */
const LABEL_BUDGET = 220

const SELECT = 0x33e3ff

/** Whether this browser can draw 3D at all — checked up front so the screen can say so plainly. */
export function webglAvailable(): boolean {
  try {
    const canvas = document.createElement('canvas')

    return Boolean(canvas.getContext('webgl2') ?? canvas.getContext('webgl'))
  } catch {
    return false
  }
}

const ease = (t: number): number => 1 - Math.pow(1 - t, 3)

/**
 * The 3D scene: a room, what stands in it, and the lines wire could run along.
 *
 * Everything here is drawn from a {@link SpatialModel}; nothing is measured. Orbit controls give the three
 * things the screen promises — drag to rotate, scroll or pinch to zoom, right-drag or two fingers to pan.
 * It draws only when something changes, so an idle model costs nothing.
 */
export class SpatialScene {
  private readonly renderer: THREE.WebGLRenderer
  private readonly labelRenderer: CSS2DRenderer
  private readonly scene = new THREE.Scene()
  private readonly camera = new THREE.PerspectiveCamera(38, 1, 0.5, 4000)
  private readonly controls: OrbitControls
  private readonly raycaster = new THREE.Raycaster()
  private readonly resizer: ResizeObserver
  private readonly lineMaterials: LineMaterial[] = []
  private world: THREE.Group | null = null
  private model: SpatialModel | null = null
  private radius = 60
  private frame = 0
  private tween: number | null = null
  private disposed = false
  private state: SceneState = {
    layers: new Set(),
    hiddenCircuits: new Set(),
    hiddenSymbols: new Set(),
    symbolImages: true,
    symbolNames: true,
    context: true,
    labels: true,
    selected: null,
  }
  private downAt: { x: number; y: number } | null = null

  private readonly meshes = new Map<string, THREE.Mesh>()
  private readonly labels = new Map<string, CSS2DObject>()
  private readonly routeLines = new Map<string, Line2>()
  private readonly routePicks = new Map<string, THREE.Mesh[]>()
  private readonly drops = new Map<string, LineSegments2>()
  private readonly sprites = new Map<string, THREE.Sprite>()
  private readonly symbolTags = new Map<number, CSS2DObject>()
  private readonly textures: THREE.Texture[] = []
  private symbols: SceneSymbols = new Map()
  private context: THREE.Object3D[] = []
  private outline: THREE.LineSegments | null = null

  private readonly host: HTMLElement
  private readonly onSelect: (id: string | null) => void

  constructor(host: HTMLElement, onSelect: (id: string | null) => void) {
    this.host = host
    this.onSelect = onSelect
    this.renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' })
    this.renderer.setPixelRatio(Math.min(2, window.devicePixelRatio || 1))
    this.renderer.setClearColor(0x050b16, 1)
    host.appendChild(this.renderer.domElement)
    this.renderer.domElement.style.display = 'block'
    this.renderer.domElement.style.outline = 'none'

    this.labelRenderer = new CSS2DRenderer()
    Object.assign(this.labelRenderer.domElement.style, { position: 'absolute', inset: '0', pointerEvents: 'none' })
    host.appendChild(this.labelRenderer.domElement)

    this.scene.add(new THREE.AmbientLight(0xffffff, 0.7))
    const sun = new THREE.DirectionalLight(0xffffff, 0.9)
    sun.position.set(40, 80, 30)
    this.scene.add(sun)

    this.controls = new OrbitControls(this.camera, this.renderer.domElement)
    this.controls.screenSpacePanning = true
    this.controls.enableDamping = false
    this.controls.addEventListener('change', this.requestRender)

    const el = this.renderer.domElement
    el.addEventListener('pointerdown', this.onDown)
    el.addEventListener('pointerup', this.onUp)

    this.resizer = new ResizeObserver(() => this.resize())
    this.resizer.observe(host)
    this.resize()
  }

  // ------------------------------------------------------------------ public

  setModel(model: SpatialModel, symbols: SceneSymbols): void {
    this.model = model
    this.symbols = symbols
    this.radius = Math.max(20, Math.hypot(model.widthFt, model.depthFt, model.heightFt) / 2)
    this.build(model)
    this.apply()
    this.setView('iso', false)
  }

  update(state: SceneState): void {
    this.state = state
    this.apply()
  }

  setView(view: ViewPreset, animate = true): void {
    const target = new THREE.Vector3(0, (this.model?.heightFt ?? 12) / 3, 0)
    const direction =
      view === 'top'
        ? new THREE.Vector3(0, 1, 0.0001)
        : view === 'front'
          ? new THREE.Vector3(0, 0.12, 1)
          : new THREE.Vector3(1, 0.85, 1)
    const distance = (this.radius / Math.tan(THREE.MathUtils.degToRad(this.camera.fov / 2))) * 0.92
    this.moveTo(direction.normalize().multiplyScalar(distance).add(target), target, animate)
  }

  /** Dolly in (factor below 1) or out (above), keeping what is in the middle in the middle. */
  zoom(factor: number): void {
    const offset = this.camera.position.clone().sub(this.controls.target)
    const length = THREE.MathUtils.clamp(offset.length() * factor, this.radius * 0.2, this.radius * 6)
    this.moveTo(this.controls.target.clone().add(offset.setLength(length)), this.controls.target.clone(), true)
  }

  focus(id: string): void {
    const object = id.startsWith('c:') ? this.meshes.get(id.slice(2)) : this.routeLines.get(id.slice(2))
    if (!object) return
    const center = new THREE.Box3().setFromObject(object).getCenter(new THREE.Vector3())
    const offset = this.camera.position.clone().sub(this.controls.target)
    offset.setLength(Math.min(offset.length(), this.radius * 1.6))
    this.moveTo(center.clone().add(offset), center, true)
  }

  dispose(): void {
    this.disposed = true
    cancelAnimationFrame(this.frame)
    if (this.tween !== null) cancelAnimationFrame(this.tween)
    this.resizer.disconnect()
    this.controls.dispose()
    const el = this.renderer.domElement
    el.removeEventListener('pointerdown', this.onDown)
    el.removeEventListener('pointerup', this.onUp)
    this.clear()
    this.renderer.dispose()
    el.remove()
    this.labelRenderer.domElement.remove()
  }

  // ----------------------------------------------------------------- private

  private readonly requestRender = (): void => {
    if (this.disposed || this.frame) return
    this.frame = requestAnimationFrame(() => {
      this.frame = 0
      if (this.disposed) return
      this.renderer.render(this.scene, this.camera)
      this.labelRenderer.render(this.scene, this.camera)
    })
  }

  private resize(): void {
    const width = Math.max(1, this.host.clientWidth)
    const height = Math.max(1, this.host.clientHeight)
    this.renderer.setSize(width, height, false)
    this.renderer.domElement.style.width = `${width}px`
    this.renderer.domElement.style.height = `${height}px`
    this.labelRenderer.setSize(width, height)
    this.camera.aspect = width / height
    this.camera.updateProjectionMatrix()
    for (const material of this.lineMaterials) material.resolution.set(width, height)
    this.requestRender()
  }

  private moveTo(position: THREE.Vector3, target: THREE.Vector3, animate: boolean): void {
    if (this.tween !== null) cancelAnimationFrame(this.tween)
    if (!animate) {
      this.camera.position.copy(position)
      this.controls.target.copy(target)
      this.controls.update()
      this.requestRender()
      return
    }

    const fromPosition = this.camera.position.clone()
    const fromTarget = this.controls.target.clone()
    const start = performance.now()
    const step = (now: number) => {
      if (this.disposed) return
      const t = ease(Math.min(1, (now - start) / 380))
      this.camera.position.lerpVectors(fromPosition, position, t)
      this.controls.target.lerpVectors(fromTarget, target, t)
      this.controls.update()
      this.requestRender()
      this.tween = t < 1 ? requestAnimationFrame(step) : null
    }
    this.tween = requestAnimationFrame(step)
  }

  private clear(): void {
    if (this.world) {
      this.scene.remove(this.world)
      this.world.traverse((object) => {
        const item = object as THREE.Mesh
        item.geometry?.dispose?.()
        const material = item.material as THREE.Material | THREE.Material[] | undefined
        if (Array.isArray(material)) material.forEach((m) => m.dispose())
        else material?.dispose?.()
      })
    }
    for (const label of this.labels.values()) label.element.remove()
    for (const tag of this.symbolTags.values()) tag.element.remove()
    for (const texture of this.textures) texture.dispose()
    this.textures.length = 0
    this.sprites.clear()
    this.symbolTags.clear()
    this.world = null
    this.meshes.clear()
    this.labels.clear()
    this.routeLines.clear()
    this.routePicks.clear()
    this.drops.clear()
    this.lineMaterials.length = 0
    this.context = []
    this.outline = null
  }

  /**
   * The material for one symbol's picture: a badge in its colour straight away, replaced by the crop from
   * the drawing once that has loaded — so there is never a blank, and a crop that will not load costs nothing.
   */
  private pictureMaterial(symbol: SceneSymbol): THREE.SpriteMaterial {
    const badge = document.createElement('canvas')
    badge.width = 128
    badge.height = 128
    const paint = badge.getContext('2d')
    if (paint) {
      paint.fillStyle = symbol.color
      paint.beginPath()
      paint.roundRect(6, 6, 116, 116, 22)
      paint.fill()
      paint.fillStyle = '#ffffff'
      paint.font = '700 52px system-ui, sans-serif'
      paint.textAlign = 'center'
      paint.textBaseline = 'middle'
      paint.fillText(
        symbol.name
          .split(/\s+/)
          .filter(Boolean)
          .slice(0, 2)
          .map((word) => word.charAt(0).toUpperCase())
          .join(''),
        64,
        66,
      )
    }
    const fallback = new THREE.CanvasTexture(badge)
    fallback.colorSpace = THREE.SRGBColorSpace
    this.textures.push(fallback)

    const material = new THREE.SpriteMaterial({ map: fallback, transparent: true, depthWrite: false })
    material.userData['aspect'] = 1

    if (symbol.image) {
      new THREE.TextureLoader().load(
        symbol.image,
        (texture) => {
          if (this.disposed) {
            texture.dispose()

            return
          }
          texture.colorSpace = THREE.SRGBColorSpace
          this.textures.push(texture)
          material.map = texture
          material.needsUpdate = true
          const image = texture.image as { width?: number; height?: number } | undefined
          const aspect = image?.width && image?.height ? Math.min(2.2, Math.max(0.45, image.width / image.height)) : 1
          material.userData['aspect'] = aspect
          // Sprites already made for this symbol take the picture's proportions.
          for (const sprite of this.sprites.values()) {
            if (sprite.material === material) sprite.scale.x = sprite.scale.y * aspect
          }
          this.requestRender()
        },
        undefined,
        () => {
          /* keep the badge */
        },
      )
    }

    return material
  }

  private build(model: SpatialModel): void {
    this.clear()
    const world = new THREE.Group()
    // The room is centred on the origin so the camera always looks at the middle of it.
    world.position.set(-model.widthFt / 2, 0, -model.depthFt / 2)
    this.world = world
    this.scene.add(world)

    const { widthFt: W, depthFt: D, heightFt: H } = model
    const u = Math.max(1, W / 60)

    // Reference grid, a little wider than the room.
    const span = Math.max(W, D) * 1.7
    const grid = new THREE.GridHelper(span, Math.round(span / 5), 0x16345a, 0x0d2038)
    grid.position.set(W / 2, -0.02, D / 2)
    world.add(grid)

    // The room: a translucent floor, and the edges and faint walls of the box around it.
    const floor = new THREE.Mesh(
      new THREE.PlaneGeometry(W, D),
      new THREE.MeshBasicMaterial({
        color: 0x1f6fa8,
        transparent: true,
        opacity: 0.42,
        side: THREE.DoubleSide,
        depthWrite: false,
      }),
    )
    floor.rotation.x = -Math.PI / 2
    floor.position.set(W / 2, 0, D / 2)
    const box = new THREE.BoxGeometry(W, H, D)
    const edges = new THREE.LineSegments(
      new THREE.EdgesGeometry(box),
      new THREE.LineBasicMaterial({ color: 0x5aa5c8, transparent: true, opacity: 0.7 }),
    )
    edges.position.set(W / 2, H / 2, D / 2)
    const walls = new THREE.Mesh(
      box,
      new THREE.MeshBasicMaterial({
        color: 0x2b7fb0,
        transparent: true,
        opacity: 0.07,
        side: THREE.BackSide,
        depthWrite: false,
      }),
    )
    walls.position.copy(edges.position)
    world.add(floor, edges, walls)
    this.context = [floor, edges, walls]

    // The colour a symbol is drawn in, as in the symbol list.
    const tint = (symbolId: number | null): THREE.Color => {
      const symbol = symbolId === null ? undefined : this.symbols.get(symbolId)

      return new THREE.Color(symbol?.color ?? '#cbd5e1')
    }
    const pictures = new Map<number, THREE.SpriteMaterial>()
    const pictureFor = (symbolId: number): THREE.SpriteMaterial | null => {
      const symbol = this.symbols.get(symbolId)
      if (!symbol) return null
      let material = pictures.get(symbolId)
      if (!material) {
        material = this.pictureMaterial(symbol)
        pictures.set(symbolId, material)
      }

      return material
    }

    // Components.
    const total = model.components.length
    for (const component of model.components) {
      const [w, h, d] = component.size
      const mesh = new THREE.Mesh(
        new THREE.BoxGeometry(w * u, h * u, d * u),
        new THREE.MeshStandardMaterial({
          // The symbol's own colour, the one its row in the list wears; the kind's when it has none.
          color: tint(component.symbolId),
          emissive: component.kind === 'light' ? tint(component.symbolId) : new THREE.Color(0x000000),
          emissiveIntensity: component.kind === 'light' ? 0.55 : 0,
          roughness: 0.55,
          metalness: 0.1,
        }),
      )
      // `y` is the bottom; a light hangs from the ceiling, so its top meets it.
      mesh.position.set(component.x, component.y + (h * u) / 2, component.z)
      mesh.userData['id'] = `c:${component.id}`
      world.add(mesh)
      this.meshes.set(component.id, mesh)

      const tag = document.createElement('div')
      tag.textContent = component.isSource
        ? `${component.label} · circuit source${component.assumed ? ' (assumed)' : ''}`
        : component.label
      Object.assign(tag.style, {
        background: 'rgba(5, 12, 28, 0.88)',
        color: '#e2e8f0',
        border: `1px solid ${component.isSource ? '#33e3ff' : 'rgba(148, 163, 184, 0.5)'}`,
        borderRadius: '5px',
        padding: '2px 6px',
        font: '600 11px/1.2 system-ui, sans-serif',
        whiteSpace: 'nowrap',
        pointerEvents: 'none',
        transform: 'translateY(-4px)',
      })
      const label = new CSS2DObject(tag)
      label.position.set(0, (h * u) / 2 + 0.6 * u, 0)
      label.userData['wanted'] = total <= LABEL_BUDGET || component.kind === 'panel' || component.kind === 'equipment'
      mesh.add(label)
      this.labels.set(component.id, label)

      // The symbol's picture, over the place it stands (under a ceiling fixture, which has no room above).
      const material = component.symbolId === null ? null : pictureFor(component.symbolId)
      if (material) {
        const sprite = new THREE.Sprite(material)
        const side = 3.2 * u
        sprite.scale.set(side * (material.userData['aspect'] ?? 1), side, 1)
        sprite.position.set(
          component.x,
          component.kind === 'light' ? component.y - 2.4 * u : component.y + h * u + 2.4 * u,
          component.z,
        )
        sprite.userData['id'] = `c:${component.id}`
        world.add(sprite)
        this.sprites.set(component.id, sprite)
      }
    }

    // One tag per symbol — its picture and name — where its places gather.
    const gathered = new Map<number, { x: number; y: number; z: number; n: number }>()
    for (const component of model.components) {
      if (component.symbolId === null || !this.symbols.has(component.symbolId)) continue
      const at = gathered.get(component.symbolId) ?? { x: 0, y: 0, z: 0, n: 0 }
      at.x += component.x
      at.y = Math.max(at.y, component.y + component.size[1] * u)
      at.z += component.z
      at.n += 1
      gathered.set(component.symbolId, at)
    }
    for (const [symbolId, at] of gathered) {
      const symbol = this.symbols.get(symbolId)!
      const tag = document.createElement('div')
      Object.assign(tag.style, {
        display: 'inline-flex',
        alignItems: 'center',
        gap: '5px',
        background: 'rgba(5, 12, 28, 0.9)',
        color: '#f1f5f9',
        border: `1px solid ${symbol.color}`,
        borderRadius: '6px',
        padding: '2px 7px 2px 3px',
        font: '600 11px/1.2 system-ui, sans-serif',
        whiteSpace: 'nowrap',
        pointerEvents: 'none',
      })
      if (symbol.image) {
        const image = document.createElement('img')
        image.src = symbol.image
        image.alt = ''
        Object.assign(image.style, {
          width: '18px',
          height: '18px',
          objectFit: 'contain',
          background: '#fff',
          borderRadius: '3px',
        })
        // A picture that will not load leaves the name and the colour, not a broken-image box.
        image.onerror = () => image.remove()
        tag.appendChild(image)
      }
      const text = document.createElement('span')
      text.textContent = `${symbol.name} ×${at.n}`
      tag.appendChild(text)
      const object = new CSS2DObject(tag)
      object.position.set(at.x / at.n, at.y + 4.5 * u, at.z / at.n)
      world.add(object)
      this.symbolTags.set(symbolId, object)
    }

    // Routes: a line along the run, and thin invisible bars along it so it can be clicked.
    const byId = new Map(model.components.map((component) => [component.id, component]))
    const circuitColor = new Map(model.circuits.map((circuit) => [circuit.id, circuit.color]))
    for (const route of model.routes) {
      const material = new LineMaterial({
        color: new THREE.Color(circuitColor.get(route.circuitId) ?? '#e2e8f0')
          .lerp(new THREE.Color(0xffffff), 0.45)
          .getHex(),
        linewidth: 2.4,
        transparent: true,
        opacity: 0.95,
      })
      this.lineMaterials.push(material)
      const geometry = new LineGeometry()
      geometry.setPositions(route.points.flat())
      const line = new Line2(geometry, material)
      line.userData['id'] = `r:${route.id}`
      line.computeLineDistances()
      world.add(line)
      this.routeLines.set(route.id, line)

      const picks: THREE.Mesh[] = []
      for (let i = 0; i < route.points.length - 1; i += 1) {
        const [ax = 0, ay = 0, az = 0] = route.points[i] ?? []
        const [bx = 0, , bz = 0] = route.points[i + 1] ?? []
        const length = Math.abs(bx - ax) + Math.abs(bz - az)
        if (length < 0.01) continue
        const alongX = Math.abs(bx - ax) >= Math.abs(bz - az)
        const pick = new THREE.Mesh(
          new THREE.BoxGeometry(alongX ? length : 1.1 * u, 1.1 * u, alongX ? 1.1 * u : length),
          new THREE.MeshBasicMaterial({ visible: false }),
        )
        pick.position.set((ax + bx) / 2, ay, (az + bz) / 2)
        pick.userData['id'] = `r:${route.id}`
        world.add(pick)
        picks.push(pick)
      }
      this.routePicks.set(route.id, picks)
    }

    // Conduit: the drop from each run down to the device it feeds, and the rise out of the panel.
    for (const route of model.routes) {
      const target = byId.get(route.toId)
      const from = byId.get(route.fromId)
      const last = route.points[route.points.length - 1]
      if (!target || !last) continue
      const positions: number[] = [last[0], route.elevation, last[2], last[0], target.y + target.size[1] * u, last[2]]
      if (from?.kind === 'panel') {
        const first = route.points[0]
        if (first) positions.push(first[0], route.elevation, first[2], first[0], from.y + from.size[1] * u, first[2])
      }
      const material = new LineMaterial({ color: 0x94a3b8, linewidth: 1.6, transparent: true, opacity: 0.9 })
      this.lineMaterials.push(material)
      const geometry = new LineSegmentsGeometry()
      geometry.setPositions(positions)
      const segments = new LineSegments2(geometry, material)
      world.add(segments)
      this.drops.set(route.id, segments)
    }

    this.resize()
  }

  /** Puts the model into the state the panels ask for. */
  private apply(): void {
    const model = this.model
    if (!model) return
    const { layers, hiddenCircuits, hiddenSymbols, symbolImages, symbolNames, context, labels, selected } = this.state
    const circuitOn = (id: string | null): boolean => id === null || !hiddenCircuits.has(id)
    const symbolOn = (id: number | null): boolean => id === null || !hiddenSymbols.has(id)
    const visibleComponents = new Map<string, boolean>()
    const standing = new Map<number, number>()

    for (const object of this.context) object.visible = context

    for (const component of model.components) {
      const visible = layers.has(component.layer) && circuitOn(component.circuitId) && symbolOn(component.symbolId)
      visibleComponents.set(component.id, visible)
      if (visible && component.symbolId !== null)
        standing.set(component.symbolId, (standing.get(component.symbolId) ?? 0) + 1)
      const mesh = this.meshes.get(component.id)
      const label = this.labels.get(component.id)
      const sprite = this.sprites.get(component.id)
      if (sprite) sprite.visible = visible && symbolImages
      if (mesh) mesh.visible = visible
      if (label)
        label.visible = visible && labels && (label.userData['wanted'] === true || selected === `c:${component.id}`)
    }

    for (const [symbolId, tag] of this.symbolTags) tag.visible = symbolNames && (standing.get(symbolId) ?? 0) > 0

    const wires = layers.has('wires')
    const conduit = layers.has('conduit')
    for (const route of model.routes) {
      // A run goes with the symbol it feeds: hide that symbol and its wire and its drop go too.
      const feeds = visibleComponents.get(route.toId) !== false
      const visible = wires && circuitOn(route.circuitId) && feeds
      const drop = this.drops.get(route.id)
      if (drop) drop.visible = conduit && circuitOn(route.circuitId) && feeds
      const line = this.routeLines.get(route.id)
      const isSelected = selected === `r:${route.id}`
      if (line) {
        line.visible = visible
        const material = line.material as LineMaterial
        material.color.set(
          isSelected
            ? SELECT
            : new THREE.Color(model.circuits.find((c) => c.id === route.circuitId)?.color ?? '#e2e8f0').lerp(
                new THREE.Color(0xffffff),
                0.45,
              ),
        )
        material.linewidth = isSelected ? 5 : 2.4
      }
      for (const pick of this.routePicks.get(route.id) ?? []) pick.visible = visible
    }

    // Outline on the selected component.
    if (this.outline) {
      this.outline.parent?.remove(this.outline)
      this.outline.geometry.dispose()
      this.outline = null
    }
    if (selected?.startsWith('c:')) {
      const mesh = this.meshes.get(selected.slice(2))
      if (mesh) {
        const { width, height, depth } = (mesh.geometry as THREE.BoxGeometry).parameters
        const outline = new THREE.LineSegments(
          new THREE.EdgesGeometry(new THREE.BoxGeometry(width * 1.35, height * 1.35, depth * 1.35)),
          new THREE.LineBasicMaterial({ color: SELECT }),
        )
        mesh.add(outline)
        this.outline = outline
      }
    }

    this.requestRender()
  }

  private readonly onDown = (event: PointerEvent): void => {
    this.downAt = { x: event.clientX, y: event.clientY }
  }

  /** A click that did not become a drag: pick what is under it, or clear the selection. */
  private readonly onUp = (event: PointerEvent): void => {
    const down = this.downAt
    this.downAt = null
    if (!down || event.button !== 0 || Math.hypot(event.clientX - down.x, event.clientY - down.y) > 4) return

    const rect = this.renderer.domElement.getBoundingClientRect()
    const pointer = new THREE.Vector2(
      ((event.clientX - rect.left) / rect.width) * 2 - 1,
      -((event.clientY - rect.top) / rect.height) * 2 + 1,
    )
    this.raycaster.setFromCamera(pointer, this.camera)

    const targets: THREE.Object3D[] = []
    for (const mesh of this.meshes.values()) if (mesh.visible) targets.push(mesh)
    for (const sprite of this.sprites.values()) if (sprite.visible) targets.push(sprite)
    for (const picks of this.routePicks.values()) for (const pick of picks) if (pick.visible) targets.push(pick)

    const hit = this.raycaster.intersectObjects(targets, false)[0]
    const id = hit ? (hit.object.userData['id'] as string | undefined) : undefined
    this.onSelect(id ?? null)
  }
}
