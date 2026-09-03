import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { CheckCheck, Combine, RotateCcw, Sparkles, X } from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Pagination,
  TextInput,
} from '@/components/common'
import {
  PageHeader,
  PageTransition,
  StepFooter,
  StepWizard,
  appLayout,
} from '@/components/layout'
import {
  DrawingOverlay,
  EstimatingComponents,
  SymbolCard,
} from '@/components/review'
import type { OccurrenceRef } from '@/components/review/SymbolBox'
import { ROUTES, routeTo } from '@/constants'
import type {
  AiReviewSummary,
  EstimatingComponent,
  OverlaySymbol,
  PageDimensions,
  Paginated,
  ReviewTally,
  SharedPageProps,
  SymbolReviewRow,
} from '@/types'

export interface AiReviewProps {
  result: AiReviewSummary
  symbols: Paginated<SymbolReviewRow>
  overlaySymbols: readonly OverlaySymbol[]
  pageDimensions: Readonly<Record<number, PageDimensions>>
  tally: ReviewTally
  /** What the estimate will need from this takeoff, and how much of it is here. */
  estimating: readonly EstimatingComponent[]
  distinctNames: readonly string[]
  /**
   * The server still accepts `search`, `status` and `sort` as query
   * parameters, but the screen no longer offers controls for them — only the
   * page a deep link opens the drawing on is read here.
   */
  filters: {
    pageNo: number | null
  }
}

/**
 * AI Review — the gate between the model's output and everything downstream.
 *
 * Each card is one detection the AI returned. Approving, rejecting, recounting,
 * renaming, merging and splitting all write to the database immediately; the
 * final JSON is generated from those decisions only, so nothing reaches a job or
 * an estimate without a person signing it off.
 */
export default function AiReview({
  result,
  symbols,
  overlaySymbols,
  pageDimensions,
  tally,
  estimating,
  distinctNames,
  filters,
}: AiReviewProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [selected, setSelected] = useState<number[]>([])
  const [mergeName, setMergeName] = useState('')
  const [selectedOccurrence, setSelectedOccurrence] = useState<OccurrenceRef | null>(null)
  const [focusRequest, setFocusRequest] = useState<OccurrenceRef | null>(null)
  const [isFinalising, setIsFinalising] = useState(false)
  const rows = symbols.data

  const toggleSelected = useCallback((id: number, isSelected: boolean) => {
    setSelected((current) =>
      isSelected ? [...new Set([...current, id])] : current.filter((value) => value !== id),
    )
  }, [])

  const selectedOnPage = selected.filter((id) => rows.some((row) => row.id === id))

  /*
   * Signing off records the decisions; it does not freeze them. The screen is
   * the same screen whichever way you arrive at it — this only decides what
   * "continue" does, because finalising twice is not a thing.
   */
  const signedOff = result.isFinalised

  const bulk = (action: 'approve' | 'reject' | 'reset') => {
    router.post(
      routeTo.reviewBulk(result.id),
      { ids: selectedOnPage, action },
      { preserveScroll: true, onSuccess: () => setSelected([]) },
    )
  }

  const merge = () => {
    router.post(
      routeTo.reviewMerge(result.id),
      { ids: selectedOnPage, name: mergeName || null },
      {
        preserveScroll: true,
        onSuccess: () => {
          setSelected([])
          setMergeName('')
        },
      },
    )
  }

  // Guards against a double click (or a resend) firing the sign-off twice —
  // the second request used to land after the first had already finalised
  // the review and come back as a confusing error.
  const finalise = () => {
    if (isFinalising) return
    setIsFinalising(true)
    // No `preserveScroll`: this leaves the review for the summary screen, and
    // a new screen opens at its own top rather than wherever the last one was
    // scrolled to — the footer button that fires this sits at the bottom.
    router.post(routeTo.reviewFinalise(result.id), {}, {
      onFinish: () => setIsFinalising(false),
    })
  }

  return (
    <PageTransition>
      <Head title={`AI Review — ${result.projectName}`} />

      <StepWizard
        current="review"
        hrefs={{ upload: ROUTES.upload, analysis: routeTo.processing(result.projectId) }}
      />

      <PageHeader
        title="AI Review"
        breadcrumbs={[
          { label: 'AI Takeoff', href: ROUTES.aiTakeoff },
          { label: result.projectName },
          { label: 'Review' },
        ]}
      />

      {flash.warning && (
        <Alert tone="warning" className="mb-4">
          {flash.warning}
        </Alert>
      )}
      {flash.success && (
        <Alert tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}

      {selectedOnPage.length > 0 && (
        <Card padding="md" variant="solid" className="mb-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone="brand">{selectedOnPage.length} selected</Badge>
              <Button size="sm" leftIcon={CheckCheck} onClick={() => bulk('approve')}>
                Approve selected
              </Button>
              <Button variant="danger" size="sm" leftIcon={X} onClick={() => bulk('reject')}>
                Reject selected
              </Button>
              <Button variant="ghost" size="sm" leftIcon={RotateCcw} onClick={() => bulk('reset')}>
                Clear decisions
              </Button>
            </div>

            <div className="flex flex-wrap items-end gap-2">
              <TextInput
                id="merge-name"
                label="Merge as"
                value={mergeName}
                onChange={(event) => setMergeName(event.target.value)}
                placeholder="Leave blank to keep the first name"
                className="sm:w-64"
              />
              <Button
                variant="secondary"
                size="sm"
                leftIcon={Combine}
                disabled={selectedOnPage.length < 2}
                onClick={merge}
              >
                Merge {selectedOnPage.length}
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setSelected([])}>
                Clear selection
              </Button>
            </div>
          </div>
        </Card>
      )}

      <DrawingOverlay
        resultId={result.id}
        pageCount={result.pageCount}
        overlaySymbols={overlaySymbols}
        pageDimensions={pageDimensions}
        distinctNames={distinctNames}
        initialPage={filters.pageNo}
        selected={selectedOccurrence}
        onSelect={setSelectedOccurrence}
        focusRequest={focusRequest}
        onFocusHandled={() => setFocusRequest(null)}
      />

      {rows.length === 0 ? (
        <Card padding="lg">
          <EmptyState
            icon={Sparkles}
            title="No detections on this drawing"
            description="The model returned nothing to review for this takeoff."
          />
        </Card>
      ) : (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {rows.map((row, index) => (
            <SymbolCard
              key={row.id}
              resultId={result.id}
              row={row}
              selected={selected.includes(row.id)}
              onSelect={toggleSelected}
              index={index}
              focused={selectedOccurrence?.reviewId === row.id}
            />
          ))}
        </div>
      )}

      <Pagination
        className="mt-6"
        page={symbols.meta.current_page}
        pageCount={symbols.meta.last_page}
        onPageChange={(page) => {
          const query = new URLSearchParams(window.location.search)
          query.set('page', String(page))
          router.get(`${routeTo.review(result.id)}?${query.toString()}`, undefined, {
            preserveState: true,
          })
        }}
        summary={
          symbols.meta.total > 0
            ? `Showing ${symbols.meta.from}–${symbols.meta.to} of ${symbols.meta.total} detections`
            : undefined
        }
        withLabels
      />

      {/* After the detections, because it answers what happens to them next. */}
      <EstimatingComponents components={estimating} />

      {/*
        Forward is always the estimate — the step after review. Signing off is
        what produces it, so an open review finalises and an already-signed-off
        one simply opens the estimate it made.
      */}
      <StepFooter
        current="review"
        continueLabel="Continue to Estimate"
        isBusy={isFinalising}
        {...(signedOff && result.estimateId
          // In the flow, so the estimate opens with its roadmap and its own way
          // forward — without this a signed-off review continued to a dead end.
          ? { href: routeTo.estimateInFlow(result.estimateId) }
          : {})}
        {...(signedOff && !result.estimateId
          ? { href: routeTo.finalSymbols(result.id) }
          : {})}
        {...(!signedOff && tally.approved === 0
          ? { blockedReason: 'Approve at least one symbol to continue.' }
          : {})}
        {...(!signedOff && tally.approved > 0
          ? { onContinue: finalise }
          : {})}
      />
    </PageTransition>
  )
}

AiReview.layout = appLayout
