export const APP_NAME = 'Breeze'
export const APP_TAGLINE = 'AI Electrical Takeoff'

/** Shared page-transition + entrance timings (seconds). */
export const MOTION = {
  fast: 0.18,
  base: 0.28,
  slow: 0.45,
  stagger: 0.06,
} as const

/** Rows per page. Must match `takeoff.per_page` in config/takeoff.php. */
export const PAGE_SIZE = 5
