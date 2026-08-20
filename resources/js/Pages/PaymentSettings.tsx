import { useState } from 'react'
import { Head, router, useForm, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { CreditCard, Plug, Plus, ShieldCheck, Trash2 } from 'lucide-react'
import {
  Alert,
  Button,
  Card,
  CardFooter,
  CardHeader,
  Checkbox,
  ConfirmDialog,
  EmptyState,
  IconBubble,
  Pagination,
  SelectField,
  StatusChip,
  Table,
} from '@/components/common'
import { AddPaymentMethodModal, ManageProcessorsModal } from '@/components/payments'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  PAYMENT_PROCESSOR_STATUS_LABEL,
  PAYMENT_PROCESSOR_STATUS_TONE,
  PAYMENT_TRANSACTION_STATUS_LABEL,
  PAYMENT_TRANSACTION_STATUS_TONE,
  ROUTES,
  routeTo,
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
 * Payment Settings — real processor connections, real saved methods, real
 * billing preferences, and the real transaction history behind them all.
 * Nothing here is a static mock of the reference screenshot: every
 * processor starts Not Connected until real credentials pass a real test
 * call, and the payment method list is empty until one is actually added.
 */
export default function PaymentSettings({
  processors,
  paymentMethods,
  connectedProcessors,
  billingSettings,
  paymentTermsOptions,
  currencyOptions,
  transactions,
}: PaymentSettingsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)

  const manageProcessors = useDisclosure()
  const addMethod = useDisclosure()
  const deleteMethodDialog = useDisclosure()
  const [pendingDelete, setPendingDelete] = useState<PaymentMethod | null>(null)

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  const billingForm = useForm({
    auto_send_invoices: billingSettings.autoSendInvoices,
    include_payment_instructions: billingSettings.includePaymentInstructions,
    send_payment_reminders: billingSettings.sendPaymentReminders,
    apply_late_fees_automatically: billingSettings.applyLateFeesAutomatically,
    default_payment_terms: billingSettings.defaultPaymentTerms,
    default_currency: billingSettings.defaultCurrency,
  })

  const saveBillingSettings = () => {
    billingForm.put(routeTo.billingSettingsUpdate, { preserveScroll: true })
  }

  const makeDefault = (method: PaymentMethod) => {
    router.patch(routeTo.paymentMethodDefault(method.id), {}, { preserveScroll: true })
  }

  const requestDeleteMethod = (method: PaymentMethod) => {
    setPendingDelete(method)
    deleteMethodDialog.open()
  }

  const confirmDeleteMethod = () => {
    if (!pendingDelete) return
    router.delete(routeTo.paymentMethodDestroy(pendingDelete.id), { preserveScroll: true })
    setPendingDelete(null)
    deleteMethodDialog.close()
  }

  const lastTestedAt = processors
    .map((p) => p.lastTestedAt)
    .filter((value): value is string => Boolean(value))
    .sort()
    .reverse()[0]

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
        subtitle="Manage your payment processors and default payment methods"
        actions={
          <Button leftIcon={Plug} onClick={manageProcessors.open}>
            Manage Integrations
          </Button>
        }
      />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert key={displayNotice} tone={flash.warning ? 'warning' : 'success'} className="mb-6" onDismiss={() => setDismissed(displayNotice)}>
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* ============================================ Connected Processors === */}
        <Card>
          <CardHeader title="Connected Payment Processors" subtitle="Manage your payment processor connections" />

          <ul className="space-y-3">
            {processors.map((processor) => (
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

        {/* ============================================ Default Payment Method = */}
        <Card>
          <CardHeader title="Default Payment Method" subtitle="Select your preferred payment method for transactions" />

          {paymentMethods.length === 0 ? (
            <EmptyState
              icon={CreditCard}
              title="No payment methods yet"
              description={
                connectedProcessors.length === 0
                  ? 'Connect a payment processor before adding a payment method.'
                  : 'Add a payment method to set a default for transactions.'
              }
            />
          ) : (
            <ul className="space-y-3">
              {paymentMethods.map((method) => (
                <li
                  key={method.id}
                  className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 p-4"
                >
                  <button
                    type="button"
                    onClick={() => !method.isDefault && makeDefault(method)}
                    className="flex flex-1 items-center gap-3 text-left"
                    aria-label={`Make ${method.brand} ending in ${method.lastFour} the default`}
                  >
                    <span
                      className={`grid size-5 shrink-0 place-items-center rounded-full border-2 ${method.isDefault ? 'border-brand bg-brand' : 'border-hairline-strong'}`}
                      aria-hidden
                    >
                      {method.isDefault && <span className="size-2 rounded-full bg-white" />}
                    </span>
                    <span>
                      <p className="font-medium text-white">
                        {method.brand} ending in {method.lastFour}
                        {method.isExpired && <span className="ml-2 text-xs text-status-danger">Expired</span>}
                      </p>
                      <p className="text-sm text-white/70">Expires {method.expiry}</p>
                    </span>
                  </button>

                  <div className="flex items-center gap-2">
                    {method.isDefault && <StatusChip hideDot tone="brand" label="Default" />}
                    <button
                      type="button"
                      aria-label={`Remove ${method.brand} ending in ${method.lastFour}`}
                      onClick={() => requestDeleteMethod(method)}
                      className="rounded-full p-1.5 text-white/60 transition-colors hover:bg-status-danger/15 hover:text-status-danger"
                    >
                      <Trash2 size={16} aria-hidden />
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}

          <CardFooter>
            <Button leftIcon={Plus} onClick={addMethod.open} disabled={connectedProcessors.length === 0}>
              Add Payment Method
            </Button>
          </CardFooter>
        </Card>
      </div>

      {/* ==================================================== Billing Settings = */}
      <Card className="mt-6">
        <CardHeader title="Billing Settings" subtitle="Configure your billing preferences and invoice settings" />

        <div className="grid gap-8 lg:grid-cols-2">
          <div className="space-y-3">
            <h4 className="text-md font-semibold text-white">Invoice Settings</h4>
            {/*
              `Checkbox`'s root is `inline-flex` — in a column this wide, two
              short labels fit side by side unless each is forced onto its
              own block. `space-y-*` alone only adds margin, it doesn't stop
              inline siblings from sharing a line.
            */}
            <div>
              <Checkbox
                id="auto-send-invoices"
                label="Automatically send invoices"
                checked={billingForm.data.auto_send_invoices}
                onChange={(e) => billingForm.setData('auto_send_invoices', e.target.checked)}
              />
            </div>
            <div>
              <Checkbox
                id="include-payment-instructions"
                label="Include payment instructions"
                checked={billingForm.data.include_payment_instructions}
                onChange={(e) => billingForm.setData('include_payment_instructions', e.target.checked)}
              />
            </div>
            <div>
              <Checkbox
                id="send-payment-reminders"
                label="Send payment reminders"
                checked={billingForm.data.send_payment_reminders}
                onChange={(e) => billingForm.setData('send_payment_reminders', e.target.checked)}
              />
            </div>
            <div>
              <Checkbox
                id="apply-late-fees"
                label="Apply late fees automatically"
                checked={billingForm.data.apply_late_fees_automatically}
                onChange={(e) => billingForm.setData('apply_late_fees_automatically', e.target.checked)}
              />
            </div>
          </div>

          <div className="space-y-4">
            <h4 className="text-md font-semibold text-white">Payment Terms</h4>
            <SelectField
              id="default-payment-terms"
              label="Default Payment Terms"
              options={paymentTermsOptions}
              value={billingForm.data.default_payment_terms}
              onChange={(e) => billingForm.setData('default_payment_terms', e.target.value)}
            />
            <SelectField
              id="default-currency"
              label="Default Currency"
              options={currencyOptions}
              value={billingForm.data.default_currency}
              onChange={(e) => billingForm.setData('default_currency', e.target.value)}
            />
          </div>
        </div>

        <CardFooter>
          <Button isLoading={billingForm.processing} onClick={saveBillingSettings}>
            Save Changes
          </Button>
          <Button variant="white" onClick={() => billingForm.reset()}>
            Cancel
          </Button>
        </CardFooter>
      </Card>

      {/* ===================================================== Payment Security = */}
      <Card className="mt-6">
        <div className="flex gap-4">
          <IconBubble icon={ShieldCheck} tone="success" />
          <div>
            <h3 className="text-lg font-semibold text-white">Payment Security</h3>
            <p className="mt-1 text-md text-white/85">
              Processor credentials are encrypted at rest and never sent to the browser. We never store full card numbers
              or CVVs — only the brand, last four digits and expiry a processor returns.
            </p>
            {lastTestedAt && (
              <p className="mt-2 text-sm text-white/60">Last connection check completed {formatModified(lastTestedAt)}.</p>
            )}
          </div>
        </div>
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
      <AddPaymentMethodModal isOpen={addMethod.isOpen} onClose={addMethod.close} connectedProcessors={connectedProcessors} />

      <ConfirmDialog
        isOpen={deleteMethodDialog.isOpen}
        tone="danger"
        title={`Remove ${pendingDelete?.brand ?? ''} ending in ${pendingDelete?.lastFour ?? ''}?`}
        description="This removes the saved reference only — it does not affect anything on the processor's side."
        confirmLabel="Remove"
        confirmVariant="danger"
        onConfirm={confirmDeleteMethod}
        onCancel={() => {
          setPendingDelete(null)
          deleteMethodDialog.close()
        }}
      />
    </PageTransition>
  )
}

PaymentSettings.layout = appLayout
