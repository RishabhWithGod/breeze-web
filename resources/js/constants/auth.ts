/**
 * The seeded demo account, surfaced by the login form's "Fill" button.
 *
 * These are real credentials now — DemoDataSeeder creates this user — so the
 * values here must stay in step with the seeder.
 */
export const DEMO_CREDENTIALS = {
  email: 'demo@breeze.ai',
  password: 'breeze123',
} as const

export const PASSWORD_MIN_LENGTH = 8

/** Pragmatic email pattern — deliberately permissive, matches HTML5 semantics. */
export const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

export const AUTH_HIGHLIGHTS: readonly string[] = [
  'Symbol detection across full drawing sets',
  'Material quantities and labour hours in minutes',
  'Confidence scoring on every counted device',
]
