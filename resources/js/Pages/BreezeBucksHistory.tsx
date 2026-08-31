import { Head, router } from '@inertiajs/react'
import { ArrowLeft, Receipt } from 'lucide-react'
import { ButtonLink, Card, EmptyState, Pagination, StatusChip, Table } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { BREEZE_BUCKS_HISTORY_FILTERS, BREEZE_BUCKS_TYPE_LABEL, BREEZE_BUCKS_TYPE_TONE, ROUTES } from '@/constants'
import type { BreezeBucksHistoryPage, TableColumn } from '@/types'
import { formatModified } from '@/utils'

export interface BreezeBucksHistoryProps {
  history: BreezeBucksHistoryPage
  filters: { type: string }
}

/**
 * The real transaction history screen — its own page, reached from "View
 * History" on the Breeze Bucks landing page. Every row is a real
 * `breeze_bucks_transactions` record for the signed-in user, filtered and
 * paginated on the backend.
 */
export default function BreezeBucksHistory({ history, filters }: BreezeBucksHistoryProps) {
  const activeFilter = filters.type

  const changeFilter = (type: string) => {
    router.get(ROUTES.breezeBucksHistory, type === 'all' ? {} : { type }, { preserveScroll: true, preserveState: true })
  }

  const columns: TableColumn<BreezeBucksHistoryPage['data'][number]>[] = [
    { key: 'date', header: 'Date', render: (row) => <span className="whitespace-nowrap text-white/85">{formatModified(row.date)}</span> },
    {
      key: 'type',
      header: 'Type',
      render: (row) => <StatusChip hideDot tone={BREEZE_BUCKS_TYPE_TONE[row.type]} label={BREEZE_BUCKS_TYPE_LABEL[row.type]} />,
    },
    { key: 'description', header: 'Description', render: (row) => <span className="text-white">{row.description}</span> },
    {
      key: 'amount',
      header: 'Amount',
      render: (row) => (
        <span className={`whitespace-nowrap font-medium tabular-nums ${row.amount >= 0 ? 'text-status-success' : 'text-status-danger'}`}>
          {row.amount >= 0 ? '+' : ''}
          {row.amount} BB
        </span>
      ),
    },
    { key: 'balanceAfter', header: 'Balance After', render: (row) => <span className="tabular-nums text-white/85">{row.balanceAfter} BB</span> },
    { key: 'status', header: 'Status', render: (row) => <span className="capitalize text-white/70">{row.status}</span> },
  ]

  return (
    <PageTransition>
      <Head title="Breeze Bucks History" />

      <PageHeader
        title="Breeze Bucks History"
        subtitle="Every point earned, redeemed or adjusted on your account."
        actions={
          <ButtonLink href={ROUTES.breezeBucks} variant="secondary" leftIcon={ArrowLeft}>
            Back to Breeze Bucks
          </ButtonLink>
        }
      />

      <Card>
        <div className="mb-4 flex flex-wrap gap-2">
          {BREEZE_BUCKS_HISTORY_FILTERS.map((filter) => (
            <button
              key={filter.value}
              type="button"
              onClick={() => changeFilter(filter.value)}
              className={`rounded-pill px-3 py-1.5 text-sm font-medium transition-colors ${
                activeFilter === filter.value ? 'bg-brand text-brand-ink' : 'bg-white/10 text-white/80 hover:bg-white/20'
              }`}
            >
              {filter.label}
            </button>
          ))}
        </div>

        {history.data.length === 0 ? (
          <EmptyState icon={Receipt} title="No Breeze Bucks transactions yet." />
        ) : (
          <>
            <Table dense variant="lined" headerVariant="plain" columns={columns} rows={history.data} getRowId={(row) => row.id} caption="Breeze Bucks history" />
            <Pagination
              withLabels
              tone="light"
              className="mt-6"
              page={history.meta.current_page}
              pageCount={history.meta.last_page}
              onPageChange={(page) =>
                router.get(ROUTES.breezeBucksHistory, { page, type: activeFilter === 'all' ? undefined : activeFilter }, { preserveScroll: true, preserveState: true })
              }
              summary={`Showing ${history.data.length} of ${history.meta.total} transactions`}
            />
          </>
        )}
      </Card>
    </PageTransition>
  )
}

BreezeBucksHistory.layout = appLayout
