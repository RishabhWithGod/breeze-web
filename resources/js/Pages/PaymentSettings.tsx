import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { CreditCard, Plus } from 'lucide-react'
import {
  Alert,
  Button,
  Card,
  CardFooter,
  CardHeader,
  EmptyState,
  IconBubble,
  Pagination,
  StatusChip,
  Table,
} from '@/components/common'
import { ManageProcessorsModal } from '@/components/payments'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  PAYMENT_PROCESSOR_STATUS_LABEL,
  PAYMENT_PROCESSOR_STATUS_TONE,
  PAYMENT_TRANSACTION_STATUS_LABEL,
  PAYMENT_TRANSACTION_STATUS_TONE,
  ROUTES,
} from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  BillingSettings,
  PaymentMethod,
  PaymentProcessor,
  PaymentTransactionsPage,
  SelectOption,
  SharedPageProps,
  TableColumn,
} from '@/types'
import { formatCurrency, formatModified } from '@/utils'

export interface PaymentSettingsProps {
  processors: readonly PaymentProcessor[]
  paymentMethods: readonly PaymentMethod[]
  connectedProcessors: readonly PaymentProcessor[]
  billingSettings: BillingSettings
  paymentTermsOptions: readonly SelectOption[]
  currencyOptions: readonly SelectOption[]
  transactions: PaymentTransactionsPage
  can: { manage: boolean }
}

/**
 * Payment Settings — trimmed, for now, to just the Stripe connection and the
 * real transaction history behind it. PayPal/Square, saved payment methods
 * and billing preferences all still exist server-side (nothing here deletes
 * them) — this screen just isn't showing them at the moment.
 */
export default function PaymentSettings({
  processors,
  transactions,
}: PaymentSettingsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)

  const manageProcessors = useDisclosure()

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  // Only Stripe is shown here right now — PayPal/Square rows stay hidden
  // even though the backend still seeds and can connect them.
  const stripeOnly = processors.filter((processor) => processor.key === 'stripe')

  const transactionColumns: TableColumn<PaymentTransactionsPage['data'][number]>[] = [
    { key: 'date', header: 'Date', render: (row) => <span className="whitespace-nowrap text-white/85">{formatModified(row.date)}</span> },
    { key: 'description', header: 'Description', render: (row) => <span className="text-white">{row.description}</span> },
    { key: 'amount', header: 'Amount', render: (row) => <span className="whitespace-nowrap tabular-nums text-white">{formatCurrency(row.amount, 2)}</span> },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <StatusChip hideDot tone={PAYMENT_TRANSACTION_STATUS_TONE[row.status] ?? 'neutral'} label={PAYMENT_TRANSACTION_STATUS_LABEL[row.status] ?? row.status} />
      ),
    },
    { key: 'processor', header: 'Processor', render: (row) => <span className="text-white/85">{row.processorName}</span> },
  ]

  return (
    <PageTransition>
      <Head title="Payment Settings" />

      <PageHeader
        title="Payment Settings"
        subtitle="Manage your Stripe connection and view payment history"
      />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert key={displayNotice} tone={flash.warning ? 'warning' : 'success'} className="mb-6" onDismiss={() => setDismissed(displayNotice)}>
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      {/* ============================================ Connected Processors === */}
      <Card>
        <CardHeader title="Connected Payment Processors" subtitle="Manage your payment processor connections" />

        <ul className="space-y-3">
          {stripeOnly.map((processor) => (
            <li key={processor.id} className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 p-4">
              <div className="flex items-center gap-3">
                <IconBubble icon={CreditCard} tone={processor.isConnected ? 'brand' : 'neutral'} size="sm" />
                <div>
                  <p className="font-semibold text-white">{processor.displayName}</p>
                  <p className="text-sm text-white/70">
                    {processor.connectedAt ? `Connected on ${formatModified(processor.connectedAt)}` : 'Not yet connected'}
                  </p>
                </div>
              </div>
              <StatusChip hideDot tone={PAYMENT_PROCESSOR_STATUS_TONE[processor.status]} label={PAYMENT_PROCESSOR_STATUS_LABEL[processor.status]} />
            </li>
          ))}
        </ul>

        <CardFooter>
          <Button variant="secondary" leftIcon={Plus} onClick={manageProcessors.open}>
            Add Payment Processor
          </Button>
        </CardFooter>
      </Card>

      {/* ===================================================== Payment History = */}
      <Card className="mt-6">
        <CardHeader title="Payment History" />

        {transactions.data.length === 0 ? (
          <EmptyState icon={CreditCard} title="No payment transactions yet." />
        ) : (
          <>
            <Table
              dense
              variant="lined"
              headerVariant="plain"
              columns={transactionColumns}
              rows={transactions.data}
              getRowId={(row) => row.id}
              caption="Payment transactions"
            />
            <Pagination
              withLabels
              tone="light"
              className="mt-6"
              page={transactions.meta.current_page}
              pageCount={transactions.meta.last_page}
              onPageChange={(page) => router.get(ROUTES.settings, { page }, { preserveScroll: true, preserveState: true })}
              summary={`Showing ${transactions.data.length} of ${transactions.meta.total} transactions`}
            />
          </>
        )}
      </Card>

      <ManageProcessorsModal isOpen={manageProcessors.isOpen} onClose={manageProcessors.close} processors={processors} />
    </PageTransition>
  )
}

PaymentSettings.layout = appLayout
