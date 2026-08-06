import { useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { Moon, Sun } from 'lucide-react'
import { cn } from '@/utils'

type Theme = 'dark' | 'light'

/**
 * Theme switch — UI only.
 *
 * The app currently ships a single dark theme; this control demonstrates the
 * interaction and holds its own state so a real theme provider can be dropped
 * in later without touching the header.
 */
export function ThemeToggle({ className }: { className?: string }) {
  const [theme, setTheme] = useState<Theme>('dark')
  const isDark = theme === 'dark'

  return (
    <button
      type="button"
      onClick={() => setTheme(isDark ? 'light' : 'dark')}
      aria-label={isDark ? 'Switch to light theme' : 'Switch to dark theme'}
      aria-pressed={!isDark}
      title={`${isDark ? 'Light' : 'Dark'} theme (UI only)`}
      className={cn(
        'relative grid size-10 place-items-center overflow-hidden rounded-full text-white',
        'transition-colors hover:bg-white/10 hover:text-brand',
        className,
      )}
    >
      <AnimatePresence mode="wait" initial={false}>
        <motion.span
          key={theme}
          initial={{ opacity: 0, rotate: -90, scale: 0.6 }}
          animate={{ opacity: 1, rotate: 0, scale: 1 }}
          exit={{ opacity: 0, rotate: 90, scale: 0.6 }}
          transition={{ duration: 0.22 }}
          className="grid place-items-center"
        >
          {isDark ? <Moon size={20} aria-hidden /> : <Sun size={20} aria-hidden />}
        </motion.span>
      </AnimatePresence>
    </button>
  )
}
