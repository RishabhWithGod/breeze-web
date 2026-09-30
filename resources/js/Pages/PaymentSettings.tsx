import { useState } from 'react'
import { Head, router, useForm, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import type { LucideIcon } from 'lucide-react'
import {
  BadgeCheck,
  BarChart3,
  Building2,
  CalendarDays,
  CheckCircle2,
  ClipboardList,
  CreditCard,
  FileText,
  Globe,
  Landmark,
  Layers,
  Mail,
  MapPin,
  Package,
  Pencil,
  Phone,
  RefreshCw,
  ShieldCheck,
  SlidersHorizontal,
  UserRound,
  UsersRound,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  EmptyState,
  Pagination,
  SelectField,
  StatusChip,
  Switch,
  Table,
} from '@/components/common'
import { AddPaymentMethodModal, ChangePlanModal, ManageProcessorsModal, ProcessorMark } from '@/components/payments'
import { appLayout, CompanyMark, PageHeader, PageTransition } from '@/components/layout'
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
  CompanyDetails,
  BillingSettings,
  PaymentMethod,
  PaymentProcessor,
  PaymentTransactionsPage,
  SelectOption,
  SubscriptionPayment,
  SubscriptionSummary,
  SharedPageProps,
  TableColumn,
} from '@/types'
import { cn, formatCalendarDate, formatCents, formatCurrency, formatModified } from '@/utils'

export interface PaymentSettingsProps {
  subscription: SubscriptionSummary
  /** Null until this account has paid for its subscription through Stripe. */
  subscriptionPayment: SubscriptionPayment | null
  /** The company this account belongs to, as entered at setup; null when it has none. */
  company: CompanyDetails | null
  /** True for the company's owner, who may correct its details. */
  canEditCompany: boolean
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
  subscription,
  subscriptionPayment,
  company,
  canEditCompany,
  processors,
  connectedProcessors,
  billingSettings,
  paymentTermsOptions,
  currencyOptions,
  transactions,
}: PaymentSettingsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)

  // Which tab is open lives in the URL, so a link or a reload lands on the same one.
  const [tab, setTab] = useState<SettingsTab>(() => {
    const requested = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('tab')

    return TABS.some((item) => item.key === requested) ? (requested as SettingsTab) : 'subscription'
  })
  const selectTab = (next: SettingsTab) => {
    setTab(next)
    const params = new URLSearchParams(window.location.search)
    params.set('tab', next)
    window.history.replaceState(window.history.state, '', `${window.location.pathname}?${params.toString()}`)
  }

  const manageProcessors = useDisclosure()
  const changePlan = useDisclosure()
  const addMethod = useDisclosure()

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  // Only Stripe is shown here right now — PayPal/Square rows stay hidden
  // even though the backend still seeds and can connect them.
  const stripeOnly = processors.filter((processor) => processor.key === 'stripe')

  const transactionColumns: TableColumn<PaymentTransactionsPage['data'][number]>[] = [
    {
      key: 'date',
      header: 'Date',
      render: (row) => <span className="whitespace-nowrap text-white/85">{formatModified(row.date)}</span>,
    },
    {
      key: 'description',
      header: 'Description',
      render: (row) => <span className="text-white">{row.description}</span>,
    },
    {
      key: 'amount',
      header: 'Amount',
      render: (row) => (
        <span className="whitespace-nowrap tabular-nums text-white">{formatCurrency(row.amount, 2)}</span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <StatusChip
          hideDot
          tone={PAYMENT_TRANSACTION_STATUS_TONE[row.status] ?? 'neutral'}
          label={PAYMENT_TRANSACTION_STATUS_LABEL[row.status] ?? row.status}
        />
      ),
    },
    {
      key: 'processor',
      header: 'Processor',
      render: (row) => <span className="text-white/85">{row.processorName}</span>,
    },
  ]

  const stripe = stripeOnly[0]
  const connected = stripe?.isConnected ?? false

  const scrollTo = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  const scrollToHistory = () => scrollTo('payment-history')

  const isActivePlan = subscription.status === 'active'

  return (
    <PageTransition>
      <Head title="Settings" />

      <PageHeader title="Settings" subtitle="Manage your company, preferences, security, and subscription." />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert
            key={displayNotice}
            tone={flash.warning ? 'warning' : 'success'}
            className="mb-5"
            onDismiss={() => setDismissed(displayNotice)}
          >
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      {/* ===================================================== Tabs ========== */}
      <nav
        aria-label="Settings sections"
        className="mb-5 grid grid-cols-2 overflow-hidden rounded-card border border-hairline glass sm:grid-cols-3"
      >
        {TABS.map((item) => {
          const active = item.key === tab
          const classes = cn(
            'relative flex items-center justify-center gap-3 px-4 py-4 text-md font-semibold transition-colors',
            active ? 'text-white' : 'text-white/85 hover:bg-white/6 hover:text-white',
          )
          const content = (
            <>
              <item.icon size={20} aria-hidden />
              {item.label}
              {active && <span className="absolute inset-x-0 bottom-0 h-1 rounded-full bg-brand" aria-hidden />}
            </>
          )

          return (
            <button
              key={item.key}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => selectTab(item.key)}
              className={classes}
            >
              {content}
            </button>
          )
        })}
      </nav>

      {tab === 'company' && (
        <div className="space-y-5">
          <CompanyDetailsPanel company={company} canEdit={canEditCompany} />
          <CompanyTab
            billingSettings={billingSettings}
            paymentTermsOptions={paymentTermsOptions}
            currencyOptions={currencyOptions}
          />
          <PreferencesTab billingSettings={billingSettings} />
        </div>
      )}

      {tab === 'subscription' && (
        <>
          {/* ================================================ Subscription ======= */}
          <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
            <div className="min-w-0 space-y-5">
              <section className="rounded-card border border-brand/50 bg-linear-to-br from-brand/10 via-white/4 to-transparent p-5 shadow-glow sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                  <div className="min-w-0">
                    <p className="text-sm text-white/85">Current Plan</p>
                    <h3 className="mt-1 text-3xl font-bold text-white">{subscription.plan.name}</h3>
                  </div>
                  <StatusChip
                    pill
                    tone={isActivePlan ? 'success' : 'neutral'}
                    label={isActivePlan ? 'Active' : 'Canceled'}
                  />
                </div>

                <p className="mt-3 max-w-md text-sm text-white/85">{subscription.plan.description}</p>

                <div className="mt-5 grid gap-3 sm:grid-cols-2">
                  <Button variant="blue" size="md" fullWidth leftIcon={Layers} onClick={changePlan.open}>
                    Change Plan
                  </Button>
                  <Button
                    variant="outline"
                    size="md"
                    fullWidth
                    leftIcon={BarChart3}
                    onClick={() => scrollTo('plan-details')}
                  >
                    View Plan Details
                  </Button>
                </div>
              </section>

              <Panel icon={Package} title="Plan Features">
                <ul className="space-y-2.5 p-4">
                  {subscription.plan.features.map((feature) => (
                    <li key={feature} className="flex items-center gap-2.5 text-sm text-white/90">
                      <CheckCircle2 size={18} aria-hidden className="shrink-0 text-status-success" />
                      {feature}
                    </li>
                  ))}
                </ul>
              </Panel>
            </div>

            <div className="min-w-0 space-y-5">
              <Panel id="plan-details" icon={ClipboardList} title="Plan Details">
                <dl className="divide-y divide-hairline">
                  <Row icon={FileText} label="Plan" value={subscription.plan.name} />
                  <Row
                    icon={UsersRound}
                    label="Users"
                    value={
                      <span
                        className={
                          subscription.seats.limit !== null && subscription.seats.used > subscription.seats.limit
                            ? 'text-red-300'
                            : undefined
                        }
                      >
                        {subscription.seats.limit === null
                          ? `${subscription.seats.used} · unlimited`
                          : `${subscription.seats.used} of ${subscription.seats.limit}`}
                      </span>
                    }
                  />
                  <Row
                    icon={CalendarDays}
                    label="Billing Cycle"
                    value={subscription.billingCycle === 'yearly' ? 'Yearly' : 'Monthly'}
                  />
                  <Row
                    icon={RefreshCw}
                    label="Renewal Date"
                    value={formatCalendarDate(subscription.renewsOn, 'MMM d, yyyy')}
                  />
                </dl>
              </Panel>

              <Panel icon={BarChart3} title="Usage This Cycle">
                <ul className="divide-y divide-hairline">
                  {subscription.usage.map((item, index) => {
                    const pct = item.limit > 0 ? Math.min(100, (item.used / item.limit) * 100) : 0
                    const over = item.used > item.limit

                    return (
                      <li
                        key={item.key}
                        className="grid grid-cols-[5.5rem_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3"
                      >
                        <span className="text-xs text-white/90">{item.label}</span>
                        <span className="h-2 overflow-hidden rounded-full bg-white/12" aria-hidden>
                          <span
                            className={cn(
                              'block h-full rounded-full',
                              over ? 'bg-status-danger' : USAGE_FILL[index % USAGE_FILL.length],
                            )}
                            style={{
                              width: `${pct}%`,
                            }}
                          />
                        </span>
                        <span
                          className={cn(
                            'text-xs font-semibold whitespace-nowrap tabular-nums',
                            over ? 'text-red-300' : 'text-white',
                          )}
                        >
                          {item.used.toLocaleString()}
                          {item.unit ? ` ${item.unit}` : ''} / {item.limit.toLocaleString()}
                          {item.unit ? ` ${item.unit}` : ''}
                        </span>
                      </li>
                    )
                  })}
                </ul>
              </Panel>
            </div>
          </div>
        </>
      )}

      {tab === 'payment' && (
        <>
          <div className="grid items-stretch gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
            <div className="flex min-w-0 flex-col gap-5">
              {/* ============================================== The connection ==== */}
              <section className="flex flex-1 flex-col rounded-card border border-brand/50 bg-linear-to-br from-brand/10 via-white/4 to-transparent p-5 shadow-glow sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                  <div className="min-w-0">
                    <p className="text-sm text-white/85">Payment processor</p>
                    <div className="mt-2 flex items-center gap-4">
                      {stripe && (
                        <ProcessorMark
                          processorKey={stripe.key}
                          size="sm"
                          className={!connected ? 'opacity-50 grayscale' : undefined}
                        />
                      )}
                      <h2 className="text-2xl font-bold text-white">{stripe?.displayName ?? 'Stripe'}</h2>
                    </div>
                  </div>

                  {stripe && (
                    <StatusChip
                      pill
                      tone={PAYMENT_PROCESSOR_STATUS_TONE[stripe.status]}
                      label={PAYMENT_PROCESSOR_STATUS_LABEL[stripe.status]}
                    />
                  )}
                </div>

                <p className="mt-3 max-w-md text-sm text-white/85">
                  {connected
                    ? 'Clients can pay their invoices online, and each payment is recorded on the invoice as it clears.'
                    : 'Connect Stripe so clients can pay their invoices online. Until then, payments are recorded by hand.'}
                </p>

                <div className="mt-auto grid gap-3 pt-8 sm:grid-cols-2">
                  <Button variant="blue" size="md" fullWidth leftIcon={Layers} onClick={manageProcessors.open}>
                    {connected ? 'Manage Processor' : 'Connect Stripe'}
                  </Button>
                  <Button variant="outline" size="md" fullWidth leftIcon={BarChart3} onClick={scrollToHistory}>
                    View Payment History
                  </Button>
                </div>
              </section>
            </div>

            <div className="flex min-w-0 flex-col gap-5">
              {/* ============================================== Connection facts == */}
              <Panel grow icon={Landmark} title="Connection Details">
                <dl className="divide-y divide-hairline">
                  <Row icon={FileText} label="Processor" value={stripe?.displayName ?? 'Stripe'} />
                  <Row
                    icon={ShieldCheck}
                    label="Status"
                    value={
                      stripe ? (
                        <StatusChip
                          hideDot
                          tone={PAYMENT_PROCESSOR_STATUS_TONE[stripe.status]}
                          label={PAYMENT_PROCESSOR_STATUS_LABEL[stripe.status]}
                        />
                      ) : (
                        'Not set up'
                      )
                    }
                  />
                  <Row
                    icon={CalendarDays}
                    label="Connected on"
                    value={stripe?.connectedAt ? formatModified(stripe.connectedAt) : 'Not yet connected'}
                  />
                  <Row
                    icon={RefreshCw}
                    label="Last tested"
                    value={stripe?.lastTestedAt ? formatModified(stripe.lastTestedAt) : 'Never'}
                  />
                  <Row icon={CreditCard} label="Payments recorded" value={String(transactions.meta.total)} />
                </dl>
              </Panel>

              {/* ============================================== What it gives you = */}
            </div>
          </div>

          {subscriptionPayment && (
            <div className="mt-5">
              <Panel icon={CreditCard} title="Subscription Payment">
                <div className="grid gap-5 p-5 md:grid-cols-2">
                  <div className="flex items-center gap-4 rounded-panel border border-hairline bg-white/4 p-4">
                    <span className="grid h-11 w-16 shrink-0 place-items-center rounded-panel bg-white text-sm font-extrabold tracking-wide text-blue-800 uppercase italic">
                      {subscriptionPayment.card.brand}
                    </span>
                    <div className="min-w-0">
                      <p className="text-md font-semibold tracking-wider text-white">
                        •••• •••• •••• {subscriptionPayment.card.lastFour}
                      </p>
                      <p className="text-sm text-white/70">
                        Expires {subscriptionPayment.card.expiry} · {subscriptionPayment.card.holder}
                      </p>
                      {subscriptionPayment.card.billingAddress && (
                        <p className="mt-1 truncate text-xs text-white/60">{subscriptionPayment.card.billingAddress}</p>
                      )}
                    </div>
                  </div>

                  <dl className="divide-y divide-hairline rounded-panel border border-hairline">
                    <Row icon={FileText} label="Plan" value={subscriptionPayment.planName} />
                    <Row
                      icon={CreditCard}
                      label={subscriptionPayment.billingCycle === 'yearly' ? 'Yearly Charge' : 'Monthly Charge'}
                      value={formatCents(subscriptionPayment.amount)}
                    />
                    <Row
                      icon={RefreshCw}
                      label="Next Renewal"
                      value={formatCalendarDate(subscriptionPayment.renewsOn, 'MMM d, yyyy')}
                    />
                  </dl>
                </div>
                <p className="border-t border-hairline px-5 py-3 text-xs text-white/65">
                  {subscriptionPayment.viaStripe
                    ? 'Paid through Stripe. Your card details are held by Stripe, not by Breeze.Ai.'
                    : 'Recorded on this account.'}
                </p>
              </Panel>
            </div>
          )}

          <div className="mt-5">
            {/* ============================================== Payment history === */}
            <Panel id="payment-history" icon={CreditCard} title="Payment History">
              {transactions.data.length === 0 ? (
                <EmptyState icon={CreditCard} title="No payment transactions yet." />
              ) : (
                <div className="p-4">
                  <Table
                    dense
                    variant="lined"
                    headerVariant="plain"
                    className="text-sm [&_th]:text-sm [&_td]:text-sm"
                    columns={transactionColumns}
                    rows={transactions.data}
                    getRowId={(row) => row.id}
                    caption="Payment transactions"
                  />
                  <Pagination
                    withLabels
                    className="mt-4"
                    page={transactions.meta.current_page}
                    pageCount={transactions.meta.last_page}
                    onPageChange={(page) =>
                      router.get(
                        ROUTES.settings,
                        { tab: 'subscription', page },
                        {
                          preserveScroll: true,
                          preserveState: true,
                        },
                      )
                    }
                    summary={`Showing ${transactions.data.length} of ${transactions.meta.total} transactions`}
                  />
                </div>
              )}
            </Panel>
          </div>
        </>
      )}

      <ManageProcessorsModal
        isOpen={manageProcessors.isOpen}
        onClose={manageProcessors.close}
        processors={processors}
      />
      <AddPaymentMethodModal
        isOpen={addMethod.isOpen}
        onClose={addMethod.close}
        connectedProcessors={connectedProcessors}
      />
      <ChangePlanModal isOpen={changePlan.isOpen} onClose={changePlan.close} subscription={subscription} />
    </PageTransition>
  )
}

PaymentSettings.layout = appLayout

type SettingsTab = 'company' | 'payment' | 'subscription'

const TABS: readonly {
  key: SettingsTab
  label: string
  icon: LucideIcon
}[] = [
  { key: 'company', label: 'Company', icon: Building2 },
  { key: 'payment', label: 'Payment', icon: Landmark },
  { key: 'subscription', label: 'Subscription', icon: CreditCard },
]

/** Every billing preference, in the shape the server wants them all together. */
function useBillingForm(billing: BillingSettings) {
  const form = useForm({
    auto_send_invoices: billing.autoSendInvoices,
    include_payment_instructions: billing.includePaymentInstructions,
    send_payment_reminders: billing.sendPaymentReminders,
    apply_late_fees_automatically: billing.applyLateFeesAutomatically,
    default_payment_terms: billing.defaultPaymentTerms,
    default_currency: billing.defaultCurrency,
  })

  const save = () => form.put(routeTo.billingSettingsUpdate, { preserveScroll: true })

  return { form, save }
}

/** The defaults every new invoice starts from. */
function CompanyTab({
  billingSettings,
  paymentTermsOptions,
  currencyOptions,
}: {
  billingSettings: BillingSettings
  paymentTermsOptions: readonly SelectOption[]
  currencyOptions: readonly SelectOption[]
}) {
  const { form, save } = useBillingForm(billingSettings)
  const { data, setData, processing, errors } = form

  return (
    <Panel icon={Building2} title="Company Defaults">
      <div className="space-y-5 p-5">
        <p className="text-md text-white/80">What every new invoice starts with, unless it says otherwise.</p>

        <div className="grid max-w-3xl gap-5 sm:grid-cols-2">
          <SelectField
            id="default-currency"
            label="Default currency"
            options={currencyOptions}
            value={data.default_currency}
            onChange={(event) => setData('default_currency', event.target.value)}
            {...(errors.default_currency ? { error: errors.default_currency } : {})}
          />
          <SelectField
            id="default-payment-terms"
            label="Default payment terms"
            options={paymentTermsOptions}
            value={data.default_payment_terms}
            onChange={(event) => setData('default_payment_terms', event.target.value)}
            {...(errors.default_payment_terms ? { error: errors.default_payment_terms } : {})}
          />
        </div>

        <Button isLoading={processing} onClick={save}>
          Save Changes
        </Button>
      </div>
    </Panel>
  )
}

/** Who the company is: what was entered when it was set up. */
function CompanyDetailsPanel({ company, canEdit }: { company: CompanyDetails | null; canEdit: boolean }) {
  if (company === null) {
    return (
      <Panel icon={Building2} title="Company">
        <div className="p-5">
          <EmptyState icon={Building2} title="No company details yet." />
        </div>
      </Panel>
    )
  }

  return (
    <Panel icon={Building2} title="Company">
      <div className="flex flex-wrap items-start gap-5 border-b border-hairline p-5">
        <CompanyMark logoUrl={company.logoUrl} name={company.name} size={64} />
        <div className="min-w-0">
          <h3 className="text-2xl font-bold text-white">{company.name}</h3>
          <p className="text-sm text-white/70">{company.timezone}</p>
        </div>
        {canEdit && (
          <ButtonLink href={ROUTES.companyEdit} variant="secondary" size="sm" leftIcon={Pencil} className="ml-auto">
            Edit
          </ButtonLink>
        )}
      </div>
      <dl className="divide-y divide-hairline">
        <Row
          icon={MapPin}
          label="Business Address"
          value={<span className="whitespace-pre-line">{company.businessAddress}</span>}
        />
        <Row icon={UserRound} label="Primary Contact" value={company.primaryContact} />
        <Row icon={Phone} label="Phone" value={company.phone} />
        <Row icon={Mail} label="Email" value={company.email} />
        <Row icon={BadgeCheck} label="License Number" value={company.licenseNumber ?? '—'} />
        <Row icon={Globe} label="Time Zone" value={company.timezone} />
      </dl>
    </Panel>
  )
}

/** How invoices are sent and chased. */
function PreferencesTab({ billingSettings }: { billingSettings: BillingSettings }) {
  const { form, save } = useBillingForm(billingSettings)
  const { data, setData, processing } = form

  const toggles = [
    {
      field: 'auto_send_invoices',
      label: 'Send invoices automatically',
      hint: 'An invoice goes to the client as soon as it is issued.',
    },
    {
      field: 'include_payment_instructions',
      label: 'Include payment instructions',
      hint: 'Say how to pay on every invoice.',
    },
    {
      field: 'send_payment_reminders',
      label: 'Send payment reminders',
      hint: 'Nudge clients whose invoice is close to, or past, its due date.',
    },
    {
      field: 'apply_late_fees_automatically',
      label: 'Apply late fees automatically',
      hint: 'Add a fee to an invoice that goes overdue.',
    },
  ] as const

  return (
    <Panel icon={SlidersHorizontal} title="Invoice Preferences">
      <ul className="divide-y divide-hairline">
        {toggles.map(({ field, label, hint }) => (
          <li key={field} className="flex items-center justify-between gap-4 px-5 py-4">
            <div className="min-w-0">
              <p className="text-md font-medium text-white">{label}</p>
              <p className="text-sm text-white/70">{hint}</p>
            </div>
            <Switch
              id={`pref-${field}`}
              aria-label={label}
              checked={data[field]}
              onChange={(event) => setData(field, event.target.checked)}
            />
          </li>
        ))}
      </ul>
      <div className="border-t border-hairline p-5">
        <Button isLoading={processing} onClick={save}>
          Save Changes
        </Button>
      </div>
    </Panel>
  )
}

/** A fill colour per usage bar, in the order the figures are listed. */
const USAGE_FILL = ['bg-status-success', 'bg-brand', 'bg-status-purple', 'bg-status-warning'] as const

/** A card with a titled header bar over its body — the shape every block on this screen takes. */
function Panel({
  icon: Icon,
  title,
  id,
  grow,
  children,
}: {
  icon: LucideIcon
  title: string
  id?: string
  /** Stretch to fill the column, so the last card lines up with its neighbour. */
  grow?: boolean
  children: React.ReactNode
}) {
  return (
    <section
      id={id}
      className={cn(
        'scroll-mt-24 overflow-hidden rounded-card border border-hairline glass',
        grow && 'flex flex-1 flex-col',
      )}
    >
      <header className="flex items-center gap-3 border-b border-hairline bg-linear-to-b from-ocean-700/50 to-ocean-700/10 px-4 py-3">
        <Icon size={18} aria-hidden className="text-white/90" />
        <h2 className="text-md font-semibold text-white">{title}</h2>
      </header>
      {children}
    </section>
  )
}

/** One fact: an icon and what it is on the left, its value on the right. */
function Row({ icon: Icon, label, value }: { icon: LucideIcon; label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4 px-4 py-3">
      <dt className="flex items-center gap-2.5 text-sm text-white/85">
        <Icon size={17} aria-hidden className="text-white/85" />
        {label}
      </dt>
      <dd className="text-right text-sm font-semibold text-white">{value}</dd>
    </div>
  )
}
