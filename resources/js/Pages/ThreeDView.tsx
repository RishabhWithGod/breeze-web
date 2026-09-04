import { useMemo, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { ArrowLeft, MousePointerClick, PanelRightClose, PanelRightOpen, X } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  SelectField,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  FLAT_CAMERA,
  ThreeDLegend,
  ThreeDSymbolDetails,
  ThreeDToolbar,
  ThreeDViewer,
  markersForPage,
  moveBboxBy,
  pointOnPage,
  type ThreeDCamera,
  type ThreeDLayers,
  type ThreeDMarker,
  type ThreeDTool,
} from '@/components/threeD'
import { routeTo, ROUTES } from '@/constants'
import { categoryKey, cn, resolveSymbolColors, symbolLabel } from '@/utils'
import type { OverlaySymbol, PageDimensions, SharedPageProps } from '@/types'

export interface ThreeDViewProps {
  result: {
    readonly id: number
    readonly projectName: string | null
    readonly drawingName: string | null
    readonly pageCount: number
    readonly isFinalised: boolean
    readonly reviewUrl: string
  }
  /** The review's own rows, unfiltered — one per category, each with its occurrences. */
  overlaySymbols: readonly OverlaySymbol[]
  /** Keyed by page number. A page the engine never measured is simply absent. */
  pageDimensions: Record<number, PageDimensions>
  distinctNames: readonly string[]
  canEdit: boolean
}

const DEFAULT_LAYERS: ThreeDLayers = {
  drawing: true,
  aiSymbols: true,
  approved: true,
  rejected: true,
  manual: true,
  labels: true,
  grid: false,
}

/**
 * The spatial view of a takeoff's drawing.
 *
 * Everything here is the review's data, seen differently: the same page images,
 * the same occurrence coordinates, the same categories and colours. Every edit
 * posts to a review endpoint, so the review remains the one place a symbol's
 * status, quantity and position are decided — and finalise → estimate → job
 * carries on reading exactly what it always did.
 *
 * It is not a building. The engine measured a sheet of paper and the things on
 * it; it did not measure a room. So the drawing is a plane, the markers float
 * above it, and nothing here invents a height, a wall or a depth.
 */
export default function ThreeDView({
  result,
  overlaySymbols,
  pageDimensions,
  distinctNames,
  canEdit,
}: ThreeDViewProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [page, setPage] = useState(1)
  const [mode, setMode] = useState<'flat' | 'spatial'>('flat')
  const [tool, setTool] = useState<ThreeDTool>('select')
  /*
   * The camera, and nothing but the camera. Zoom, pan, rotation and tilt live
   * here; a symbol's page, position, status and quantity live in the review.
   * Nothing in this object is ever written anywhere — moving the camera cannot
   * reach the database, because there is no path from here to it.
   */
  const [camera, setCamera] = useState<ThreeDCamera>(FLAT_CAMERA)
  const [selected, setSelected] = useState<ThreeDMarker | null>(null)
  const [panelOpen, setPanelOpen] = useState(true)
  const [hidden, setHidden] = useState<ReadonlySet<string>>(new Set())
  const [layers, setLayers] = useState<ThreeDLayers>(DEFAULT_LAYERS)
  const [placing, setPlacing] = useState<string | null>(null)
  const [saveError, setSaveError] = useState<string | null>(null)

  /*
   * The same map the review screen builds, from the same list of names — so a
   * category is one colour on the drawing, in this legend and in the review's
   * own legend. See `resolveSymbolColors`.
   */
  const colors = useMemo(() => resolveSymbolColors(distinctNames), [distinctNames])

  const dimensions = pageDimensions[page]

  /** Only this page's markers. A drawing can carry thousands; a page carries few. */
  const pageMarkers = useMemo(
    () => markersForPage(overlaySymbols, page),
    [overlaySymbols, page],
  )

  /** What the layer switches and the legend leave visible. Nothing is deleted. */
  const visible = useMemo(
    () =>
      pageMarkers.filter((marker) => {
        if (hidden.has(categoryKey(marker.name))) return false
        if (marker.origin === 'manual' ? !layers.manual : !layers.aiSymbols) return false
        if (marker.status === 'rejected' ? !layers.rejected : !layers.approved) return false

        return true
      }),
    [pageMarkers, hidden, layers],
  )

  /** Counts for the legend: what is on this page, rejections excluded. */
  const counts = useMemo(() => {
    const map = new Map<string, number>()

    for (const marker of pageMarkers) {
      if (marker.status === 'rejected') continue
      const key = categoryKey(marker.name)
      map.set(key, (map.get(key) ?? 0) + 1)
    }

    return map
  }, [pageMarkers])

  const namesOnPage = useMemo(
    () =>
      [...new Map(pageMarkers.map((marker) => [categoryKey(marker.name), marker.name])).values()]
        .sort((a, b) => a.localeCompare(b)),
    [pageMarkers],
  )

  /* ------------------------------------------------------------- editing -- */

  /**
   * A drag, saved through the review's own move endpoint.
   *
   * The offset arrives in screen pixels and is converted back to the engine's
   * page pixels against the plane's real on-screen size — browser pixels are
   * never what gets stored. The server clamps to the page as well, and records
   * the original position the first time an occurrence moves.
   */
  const move = (marker: ThreeDMarker, delta: { x: number; y: number }) => {
    if (!dimensions || marker.occurrenceKey === null) return

    /*
     * The delta arrives already undone back to the plane's own axes — the
     * marker layer does that, because only it knows the camera the drag
     * happened under. All that is left is turning plane pixels into the page's
     * own, which is what `moveBboxBy` does against the real page size.
     *
     * Browser pixels never become drawing coordinates. The server clamps to the
     * page as well, and keeps the original position the first time one moves.
     */
    const bbox = moveBboxBy(marker.bbox, delta, { width: dimensions.width, height: dimensions.height }, dimensions)

    router.post(
      routeTo.symbolOccurrenceMove(result.id, marker.reviewId, marker.occurrenceKey),
      { bbox },
      {
        preserveScroll: true,
        preserveState: true,
        except: ['flash'],
        onError: () =>
          setSaveError(
            'Unable to save that position. Your existing review data has not been changed.',
          ),
        onSuccess: () => setSaveError(null),
      },
    )
  }

  /** A symbol placed by hand, through the review's own manual-add endpoint. */
  const place = (fraction: { x: number; y: number }) => {
    if (!dimensions || placing === null) return

    const point = pointOnPage(fraction, dimensions)
    // A small square around the click, in page pixels — the same shape the
    // review's own manual add produces.
    const side = Math.max(8, Math.round(dimensions.width * 0.012))

    router.post(
      routeTo.symbolManualAdd(result.id),
      {
        page,
        name: placing,
        bbox: [
          Math.max(0, point.x - side / 2),
          Math.max(0, point.y - side / 2),
          side,
          side,
        ],
      },
      {
        preserveScroll: true,
        preserveState: true,
        except: ['flash'],
        onSuccess: () => {
          setPlacing(null)
          setSaveError(null)
        },
        onError: () => setSaveError('That symbol could not be added.'),
      },
    )
  }

  /* ---------------------------------------------------------------- view -- */

  const zoomTo = (next: number) =>
    setCamera((current) => ({ ...current, zoom: Math.min(8, Math.max(0.2, next)) }))

  /** Back to the drawing, whole and centred, at the size it was opened at. */
  const fit = () =>
    setCamera((current) => ({ ...current, zoom: 1, panX: 0, panY: 0 }))

  /** And all the way back: flat, unturned, unzoomed, centred. */
  const reset = () => {
    setCamera(FLAT_CAMERA)
    setMode('flat')
    setTool('select')
    setSelected(null)
  }

  /*
   * Leaving spatial takes the lean and the turn with it — a flat plan that has
   * been rotated is a flat plan you have to think about.
   */
  const changeMode = (next: 'flat' | 'spatial') => {
    setMode(next)

    if (next === 'flat') {
      setCamera((current) => ({ ...current, rotation: 0, tilt: 0 }))
      setTool((current) => (current === 'rotate' ? 'select' : current))
    } else {
      setCamera((current) => ({ ...current, tilt: current.tilt === 0 ? 34 : current.tilt }))
    }
  }

  return (
    <PageTransition>
      <Head title={`3D View — ${result.projectName ?? 'Drawing'}`} />

      <PageHeader
        title="3D Electrical View"
        subtitle={
          result.drawingName
            ? `${result.projectName ?? ''} · ${result.drawingName}`
            : (result.projectName ?? undefined)
        }
        breadcrumbs={[
          { label: 'AI Takeoff', href: ROUTES.aiTakeoff },
          { label: 'Review', href: result.reviewUrl },
          { label: '3D View' },
        ]}
        actions={
          <ButtonLink href={result.reviewUrl} variant="secondary" leftIcon={ArrowLeft}>
            Back to Review
          </ButtonLink>
        }
      />

      {/*
        Two things worth saying once. What the screen is — an interactive view of
        the drawing, not a model of a building — and that nothing here needs
        saving, because every edit posts the moment it is made through the
        review's own endpoints. A Save button that did nothing would be worse.
      */}
      <p className="mb-4 text-sm text-white/70">
        Explore the electrical drawing in an interactive spatial view. Changes save as you make
        them, straight into this takeoff's review.
      </p>

      {flash.warning && (
        <Alert tone="warning" className="mb-4">
          {flash.warning}
        </Alert>
      )}
      {saveError && (
        <Alert tone="danger" className="mb-4" onDismiss={() => setSaveError(null)}>
          {saveError}
        </Alert>
      )}
      {result.isFinalised && (
        <Alert tone="info" className="mb-4">
          This takeoff is finalised, so the viewer is read-only. Reopen the review to make changes.
        </Alert>
      )}

      <Card padding="md" className="mb-4" animated={false}>
        <ThreeDToolbar
          mode={mode}
          onModeChange={changeMode}
          tool={tool}
          onToolChange={setTool}
          zoom={camera.zoom}
          onZoomIn={() => zoomTo(camera.zoom * 1.25)}
          onZoomOut={() => zoomTo(camera.zoom / 1.25)}
          onFit={fit}
          onReset={reset}
          rotation={camera.rotation}
          onRotationChange={(rotation) => setCamera((current) => ({ ...current, rotation }))}
          tilt={camera.tilt}
          onTiltChange={(tilt) => setCamera((current) => ({ ...current, tilt }))}
          page={page}
          pageCount={result.pageCount}
          onPageChange={(next) => {
            // A selection belongs to the page it was made on, and so does a
            // pan — pages can differ in size and orientation.
            setSelected(null)
            setPlacing(null)
            setCamera((current) => ({ ...current, panX: 0, panY: 0 }))
            setPage(next)
          }}
        />

        {canEdit && (
          <div className="mt-3 flex flex-wrap items-center gap-2">
            {placing === null ? (
              <>
                <SelectField
                  id="three-d-place-name"
                  aria-label="Symbol to add"
                  className="w-56"
                  value=""
                  onChange={(event) => event.target.value && setPlacing(event.target.value)}
                  options={[
                    { value: '', label: 'Add symbol…' },
                    ...distinctNames.map((name) => ({ value: name, label: symbolLabel(name) })),
                  ]}
                />
                <span className="text-2xs text-white/60">
                  Pick a category, then click where it sits on the drawing.
                </span>
              </>
            ) : (
              <>
                <span className="inline-flex items-center gap-2 rounded-full border border-brand/50 bg-brand/15 px-3 py-1 text-sm text-white">
                  <MousePointerClick size={14} aria-hidden />
                  Click the drawing to place {symbolLabel(placing)}
                </span>
                <Button size="sm" variant="white" leftIcon={X} onClick={() => setPlacing(null)}>
                  Cancel
                </Button>
              </>
            )}
          </div>
        )}
      </Card>

      {/*
        The drawing gets the room. It is what the screen is for, and the panel
        beside it can be folded away entirely when it is not being read.
      */}
      <div
        className={cn(
          'grid gap-4',
          panelOpen ? 'xl:grid-cols-[minmax(0,1fr)_19rem]' : 'xl:grid-cols-1',
        )}
      >
        <Card accent="brand" padding="sm" animated={false} className="relative h-[74vh] min-h-[30rem]">
          <button
            type="button"
            onClick={() => setPanelOpen((open) => !open)}
            aria-label={panelOpen ? 'Hide the side panel' : 'Show the side panel'}
            className="absolute top-3 right-3 z-10 hidden rounded-panel border border-hairline bg-navy-900/85 p-1.5 text-white/70 transition-colors hover:text-white xl:block"
          >
            {panelOpen ? <PanelRightClose size={15} /> : <PanelRightOpen size={15} />}
          </button>

          <ThreeDViewer
            resultId={result.id}
            page={page}
            dimensions={dimensions}
            markers={visible}
            colors={colors}
            camera={camera}
            onCameraChange={setCamera}
            tool={tool}
            selectedKey={selected?.key ?? null}
            onSelect={setSelected}
            onMove={move}
            onPlace={placing === null ? null : place}
            showDrawing={layers.drawing}
            showGrid={layers.grid}
            showLabels={layers.labels}
            canEdit={canEdit}
          />
        </Card>

        {/*
          Below the drawing on a narrow screen, beside it on a wide one — the
          drawing stays the primary thing at every size.
        */}
        <div className={cn('flex flex-col gap-4', !panelOpen && 'xl:hidden')}>
          <Card accent="success" padding="md" animated={false}>
            <ThreeDLegend
              counts={counts}
              names={namesOnPage}
              colors={colors}
              hidden={hidden}
              onToggleCategory={(key) =>
                setHidden((current) => {
                  const next = new Set(current)
                  if (next.has(key)) next.delete(key)
                  else next.add(key)

                  return next
                })
              }
              layers={layers}
              onToggleLayer={(layer) =>
                setLayers((current) => ({ ...current, [layer]: !current[layer] }))
              }
            />
          </Card>

          <Card accent="warning" padding="md" animated={false}>
            <p className="mb-3 text-2xs tracking-wide text-white/70 uppercase">
              Selected symbol
            </p>
            <ThreeDSymbolDetails
              resultId={result.id}
              marker={selected}
              color={selected ? colors.get(categoryKey(selected.name)) : undefined}
              canEdit={canEdit}
              onClose={() => setSelected(null)}
            />
          </Card>
        </div>
      </div>
    </PageTransition>
  )
}

ThreeDView.layout = appLayout
