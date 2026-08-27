import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { KeyRound, Mail, ShieldCheck } from 'lucide-react'
import {
  Alert,
  Button,
  Card,
  CardFooter,
  CardHeader,
  IconBubble,
  Pagination,
  Switch,
  Table,
} from '@/components/common'
import {
  ChangeEmailModal,
  ChangePasswordModal,
  DisableTwoFactorModal,
  EnableTwoFactorModal,
  RecoveryCodesModal,
} from '@/components/security'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  SecurityAuditLogPage,
  SecurityNotificationPreference,
  SharedPageProps,
  TableColumn,
  TwoFactorState,
} from '@/types'
import { formatModified } from '@/utils'

export interface SecuritySettingsProps {
  twoFactor: TwoFactorState
  smsConfigured: boolean
  maskedPhone: string | null
  maskedEmail: string
  notificationPreferences: readonly SecurityNotificationPreference[]
  auditLog: SecurityAuditLogPage
}

/**
 * The real security control center: 2FA only ever shows Enabled after a
 * verified OTP round-trip, SMS is honestly labeled Not Configured (no
 * provider exists), and the audit log is exactly what `security_events`
 * holds — nothing here is a static reproduction of the reference screenshot.
 */
export default function SecuritySettings({
  twoFactor,
  maskedEmail,
  notificationPreferences,
  auditLog,
}: SecuritySettingsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)

  const enable2fa = useDisclosure()
  const disable2fa = useDisclosure()
  const changeEmail = useDisclosure()
  const changePassword = useDisclosure()
  const [dismissedCodesKey, setDismissedCodesKey] = useState<string | null>(null)

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  // Recovery codes are only ever present in flash for the one response that
  // just generated them — derived directly from props, the same way
  // `displayNotice` above is, rather than mirrored into local state.
  const codesKey = flash.recoveryCodes ? flash.recoveryCodes.join(',') : null
  const showRecoveryCodes = codesKey !== null && codesKey !== dismissedCodesKey

  const toggleTwoFactor = () => {
    if (twoFactor.enabled) {
      disable2fa.open()
    } else {
      enable2fa.open()
    }
  }

  const selectMethod = (method: 'email' | 'sms') => {
    if (method === twoFactor.method) return
    router.put(routeTo.securityMethodUpdate, { method }, { preserveScroll: true })
  }

  const regenerateRecoveryCodes = () => {
    const password = window.prompt('Enter your current password to regenerate recovery codes:')
    if (!password) return
    router.post(routeTo.securityRecoveryCodes, { current_password: password }, { preserveScroll: true })
  }

  const updatePreference = (preference: SecurityNotificationPreference, channel: 'sms' | 'email', value: boolean) => {
    router.put(
      routeTo.securityNotificationUpdate(preference.eventType),
      {
        sms_enabled: channel === 'sms' ? value : preference.smsEnabled,
        email_enabled: channel === 'email' ? value : preference.emailEnabled,
      },
      { preserveScroll: true },
    )
  }

  const auditColumns: TableColumn<SecurityAuditLogPage['data'][number]>[] = [
    { key: 'activity', header: 'Activity', render: (row) => <span className="font-medium text-white">{row.activity}</span> },
    { key: 'ip', header: 'IP Address', render: (row) => <span className="whitespace-nowrap text-white/85">{row.ipAddress}</span> },
    { key: 'location', header: 'Location', render: (row) => <span className="text-white/70">{row.location}</span> },
    {
      key: 'date',
      header: 'Date & Time',
      render: (row) => <span className="whitespace-nowrap text-white/85">{formatModified(row.occurredAt)}</span>,
    },
  ]

  return (
    <PageTransition>
      <Head title="Security Settings" />

      <PageHeader
        title="Security Settings"
        subtitle="Manage your account security preferences and authentication methods"
        actions={
          <Button leftIcon={KeyRound} variant="secondary" onClick={changePassword.open}>
            Change Password
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

      {/* ============================================== Two-Factor Authentication */}
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex gap-4">
            <IconBubble icon={ShieldCheck} tone={twoFactor.enabled ? 'success' : 'neutral'} />
            <div>
              <h3 className="text-lg font-semibold text-white">Two-Factor Authentication</h3>
              <p className="mt-1 text-md text-white/85">
                {twoFactor.enabled
                  ? `Enabled since ${twoFactor.confirmedAt ? formatModified(twoFactor.confirmedAt) : 'recently'}. ${twoFactor.recoveryCodesRemaining} recovery codes remaining.`
                  : 'Add an extra verification step when signing in.'}
              </p>
            </div>
          </div>
          <Switch id="two-factor-toggle" checked={twoFactor.enabled} onChange={toggleTwoFactor} />
        </div>

        {twoFactor.enabled && (
          <CardFooter>
            <Button variant="white" onClick={regenerateRecoveryCodes}>
              Regenerate Recovery Codes
            </Button>
          </CardFooter>
        )}
      </Card>

      {/* ==================================================== Authentication Method */}
      <Card className="mt-6">
        <CardHeader title="Authentication Method" subtitle="Choose how you receive verification codes" />

        <ul className="space-y-3">
          <li className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 p-4">
            <button type="button" onClick={() => selectMethod('email')} className="flex flex-1 items-center gap-3 text-left">
              <RadioDot checked={twoFactor.method === 'email'} />
              <IconBubble icon={Mail} tone="brand" size="sm" />
              <div>
                <p className="font-medium text-white">Email Authentication</p>
                <p className="text-sm text-white/70">{maskedEmail}</p>
              </div>
            </button>
            <Button variant="white" size="sm" onClick={changeEmail.open}>
              Change
            </Button>
          </li>

        </ul>
      </Card>

      {/* ==================================================== Activity Notifications */}
      <Card className="mt-6">
        <CardHeader title="Activity Notifications" subtitle="Choose how you're notified about account activity" />

        <div className="overflow-x-auto">
          <table className="w-full text-left text-md">
            <thead>
              <tr className="text-sm text-white/60">
                <th className="py-2 pr-4 font-medium">Event</th>
                <th className="py-2 font-medium">Email</th>
              </tr>
            </thead>
            <tbody>
              {notificationPreferences.map((preference) => (
                <tr key={preference.eventType} className="border-t border-hairline">
                  <td className="py-3 pr-4 text-white">{preference.label}</td>
                  <td className="py-3">
                    <Switch
                      id={`pref-email-${preference.eventType}`}
                      checked={preference.emailEnabled}
                      onChange={(event) => updatePreference(preference, 'email', event.target.checked)}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {/* ==================================================== Security Audit Log */}
      <Card className="mt-6">
        <CardHeader title="Security Audit Log" subtitle="A record of activity on your account" />

        <Table
          dense
          variant="lined"
          headerVariant="plain"
          columns={auditColumns}
          rows={auditLog.data}
          getRowId={(row) => row.id}
          caption="Security audit log"
          emptyState={<span className="text-white/70">No security activity recorded yet.</span>}
        />

        {auditLog.meta.total > 0 && (
          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={auditLog.meta.current_page}
            pageCount={auditLog.meta.last_page}
            onPageChange={(page) => router.get(ROUTES.security, { page }, { preserveScroll: true, preserveState: true })}
            summary={`Showing ${auditLog.data.length} of ${auditLog.meta.total} events`}
          />
        )}
      </Card>

      <EnableTwoFactorModal isOpen={enable2fa.isOpen} onClose={enable2fa.close} maskedEmail={maskedEmail} />
      <DisableTwoFactorModal isOpen={disable2fa.isOpen} onClose={disable2fa.close} />
      <RecoveryCodesModal isOpen={showRecoveryCodes} onClose={() => setDismissedCodesKey(codesKey)} codes={flash.recoveryCodes ?? []} />
      <ChangeEmailModal isOpen={changeEmail.isOpen} onClose={changeEmail.close} />
      <ChangePasswordModal isOpen={changePassword.isOpen} onClose={changePassword.close} />
    </PageTransition>
  )
}

function RadioDot({ checked }: { checked: boolean }) {
  return (
    <span className={`grid size-5 shrink-0 place-items-center rounded-full border-2 ${checked ? 'border-brand bg-brand' : 'border-hairline-strong'}`} aria-hidden>
      {checked && <span className="size-2 rounded-full bg-white" />}
    </span>
  )
}

SecuritySettings.layout = appLayout
