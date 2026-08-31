import { useState } from 'react'
import { Code2 } from 'lucide-react'
import { Button } from '@/components/common'

type Theme = 'dark' | 'light'

/**
 * Theme switch — UI only.
 *
 * The app currently ships a single dark theme; this control demonstrates the
 * interaction and holds its own state so a real theme provider can be dropped in
 * later without touching the header. Built on the shared `Button` so it carries
 * the same weight as "New Takeoff", and switches to the cyan variant while it is
 * on, which is what tells you the mode is active.
 */
export function ThemeToggle({ className }: { className?: string }) {
  const [theme, setTheme] = useState<Theme>('dark')
  const isDark = theme === 'dark'

  return (
    <Button
      variant={isDark ? 'dark' : 'primary'}
      size="sm"
      leftIcon={Code2}
      onClick={() => setTheme(isDark ? 'light' : 'dark')}
      aria-pressed={!isDark}
      title="Developer mode (UI only)"
      {...(className ? { className } : {})}
    >
      Developer Mode
    </Button>
  )
}
