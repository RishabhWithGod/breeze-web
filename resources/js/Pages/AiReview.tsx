import { useCallback, useEffect, useMemo, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import {
  CheckCheck,
  Combine,
  Download,
  FileJson,
  Lock,
  RotateCcw,
  Sparkles,
  Table2,
  X,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  EmptyState,
  FilterTabs,
  Pagination,
  SearchBox,
  SectionHeading,
  SelectField,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  ApprovalHistoryPanel,
  EngineWarnings,
  PipelineStatus,
  ReviewStats,
  SymbolCard,
} from '@/components/review'
import { REVIEW_FILTERS, REVIEW_SORT_OPTIONS, ROUTES, routeTo } from '@/constants'
import { useDebouncedValue } from '@/hooks'
import type {
  AiReviewSummary,
  ApprovalHistoryEntry,
  Paginated,
  ReviewFilter,
  ReviewTally,
  SharedPageProps,
  SymbolReviewRow,
} from '@/types'
import { formatDate } from '@/utils'

export interface AiReviewProps {
  result: AiReviewSummary
  symbols: Paginated<SymbolReviewRow>
  tally: ReviewTally
  pages: readonly { page: number; total: number }[]
  distinctNames: readonly string[]
  filters: {
    search: string
    status: ReviewFilter
    sort: string
    pageNo: number | null
  }
  history: readonly ApprovalHistoryEntry[]
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
  tally,
  pages,
  filters,
  history,
}: AiReviewProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [search, setSearch] = useState(filters.search)
  const [selected, setSelected] = useState<number[]>([])
  const [mergeName, setMergeName] = useState('')
  const rows = symbols.data

  /**
   * Filters are server-side, so every change is a visit. Merging into the live
   * query string (rather than a props snapshot) keeps a debounced search from
   * resurrecting filters the user has since cleared.
   */
  const applyFilters = useCallback((changes: Record<string, string | number | null>) => {
    const query = new URLSearchParams(window.location.search)

    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === '' || value === 'all') {
        query.delete(key)
      } else {
        query.set(key, String(value))
      }
    }

    query.delete('page')

    router.get(`${routeTo.review(result.id)}?${query.toString()}`, undefined, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }, [result.id])

  const debouncedSearch = useDebouncedValue(search)

  useEffect(() => {
    if (debouncedSearch === filters.search) return
    applyFilters({ search: debouncedSearch })
  }, [debouncedSearch, filters.search, applyFilters])

  const toggleSelected = useCallback((id: number, isSelected: boolean) => {
    setSelected((current) =>
      isSelected ? [...new Set([...current, id])] : current.filter((value) => value !== id),
    )
  }, [])

  const pageOptions = useMemo(
    () => [
      { value: 'all', label: `All pages (${result.pageCount})` },
      ...pages.map((page) => ({
        value: String(page.page),
        label: `Page ${page.page} — ${page.total} detections`,
      })),
    ],
    [pages, result.pageCount],
  )

  const selectedOnPage = selected.filter((id) => rows.some((row) => row.id === id))
  const locked = result.isFinalised

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

  return (
    <PageTransition>
      <Head title={`AI Review — ${result.projectName}`} />

      <PageHeader
        title="AI Review"
        subtitle={
          `${result.detectionCount} symbols from ${result.drawingName ?? 'the drawing set'} — ` +
          'approve what is real before it reaches an estimate.' +
          (result.runId ? ` Engine run ${result.runId}.` : '')
        }
        breadcrumbs={[
          { label: 'AI Takeoff', href: ROUTES.aiTakeoff },
          { label: result.projectName },
          { label: 'Review' },
        ]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ButtonLink
              href={result.originalJsonUrl}
              variant="secondary"
              size="sm"
              leftIcon={Download}
            >
              Original AI JSON
            </ButtonLink>
            {locked ? (
              <>
                <ButtonLink
                  href={routeTo.finalSymbols(result.id)}
                  size="sm"
                  leftIcon={Table2}
                >
                  Final symbol table
                </ButtonLink>
                <Button
                  variant="secondary"
                  size="sm"
                  leftIcon={RotateCcw}
                  onClick={() => router.post(routeTo.reviewReopen(result.id))}
                >
                  Reopen review
                </Button>
              </>
            ) : (
              <Button
                size="sm"
                leftIcon={FileJson}
                disabled={tally.approved === 0}
                title="Generates final_response.json and its bill of quantities, creates the job and its estimate, then opens the job"
                onClick={() => router.post(routeTo.reviewFinalise(result.id))}
              >
                Finalize review
              </Button>
            )}
          </div>
        }
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

      {!locked && tally.approved > 0 && (
        <Alert tone="info" className="mb-4">
          Finalizing writes final_response.json, then creates the job and its estimate
          from it — {tally.approvedCount} approved items across {tally.approved} symbols.
        </Alert>
      )}

      {locked && (
        <Alert tone="brand" icon={Lock} className="mb-4">
          This takeoff was signed off
          {result.finalisedAt ? ` on ${formatDate(result.finalisedAt)}` : ''}. Reopen the
          review to change any decision.
        </Alert>
      )}

      {/* Raised by the engine about the drawing itself. */}
      <EngineWarnings warnings={result.warnings} className="mb-4" />

      <ReviewStats tally={tally} className="mb-4" />

      <PipelineStatus
        stages={result.pipelineStatus}
        processingTime={result.processingTime}
        statistics={result.lifecycleStatistics}
        className="mb-4"
      />

      <Card padding="md" className="mb-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-center">
            <SearchBox
              value={search}
              onValueChange={setSearch}
              placeholder="Search symbol or crop id…"
              aria-label="Search detections"
              containerClassName="sm:max-w-xs"
            />
            <SelectField
              id="review-page-filter"
              aria-label="Filter by drawing page"
              options={pageOptions}
              value={filters.pageNo ? String(filters.pageNo) : 'all'}
              onChange={(event) => applyFilters({ page_no: event.target.value })}
              className="sm:w-56"
            />
            <SelectField
              id="review-sort"
              aria-label="Sort detections"
              options={REVIEW_SORT_OPTIONS}
              value={filters.sort}
              onChange={(event) => applyFilters({ sort: event.target.value })}
              className="sm:w-56"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="secondary"
              size="sm"
              leftIcon={CheckCheck}
              disabled={locked || tally.pending === 0}
              onClick={() => router.post(routeTo.reviewApproveRemaining(result.id))}
            >
              Approve all pending ({tally.pending})
            </Button>
          </div>
        </div>

        <FilterTabs
          className="mt-4"
          options={REVIEW_FILTERS}
          value={filters.status}
          onChange={(value) => applyFilters({ status: value })}
          counts={{
            all: tally.total,
            pending: tally.pending,
            approved: tally.approved,
            rejected: tally.rejected,
            modified: tally.modified,
            known: tally.known,
            unknown: tally.unknown,
          }}
          solid
        />
      </Card>

      {selectedOnPage.length > 0 && !locked && (
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

      {rows.length === 0 ? (
        <Card padding="lg">
          <EmptyState
            icon={Sparkles}
            title="No detections match these filters"
            description="Clear the search or switch back to “All” to see every symbol the model returned."
          />
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
          {rows.map((row, index) => (
            <SymbolCard
              key={row.id}
              resultId={result.id}
              row={row}
              selected={selected.includes(row.id)}
              onSelect={toggleSelected}
              locked={locked}
              index={index}
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

      <Card padding="lg" className="mt-6">
        <SectionHeading
          as="h3"
          title="Approval history"
          subtitle="Every decision on this takeoff, in order"
        />
        <ApprovalHistoryPanel entries={history} />
      </Card>
    </PageTransition>
  )
}

AiReview.layout = appLayout
