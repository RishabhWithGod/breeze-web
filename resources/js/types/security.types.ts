/**
 * Security Settings — real 2FA enrollment state, authentication method,
 * masked contact details, per-event notification preferences and the audit
 * log. Mirrors `SecuritySettingsController::index()` field-for-field.
 */

export type TwoFactorMethod = 'email' | 'sms'

export interface TwoFactorState {
  readonly enabled: boolean
  readonly method: TwoFactorMethod
  readonly confirmedAt: string | null
  readonly recoveryCodesRemaining: number
}

export type SecurityEventType =
  | 'login_attempt'
  | 'password_changed'
  | 'profile_updated'
  | 'new_device_login'

export interface SecurityNotificationPreference {
  readonly eventType: SecurityEventType
  readonly label: string
  readonly smsEnabled: boolean
  readonly emailEnabled: boolean
}

export interface SecurityAuditRow {
  readonly id: number
  readonly activity: string
  readonly description: string
  readonly ipAddress: string
  readonly location: string
  readonly occurredAt: string
}

export interface SecurityAuditLogPage {
  readonly data: readonly SecurityAuditRow[]
  readonly meta: {
    readonly current_page: number
    readonly last_page: number
    readonly total: number
  }
}
