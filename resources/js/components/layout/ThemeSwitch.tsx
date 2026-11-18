import { useState } from 'react'
import { Moon, Sun } from 'lucide-react'
import { getTheme, setTheme, type Theme } from '@/lib/theme'

/** Light / dark switch. The choice is remembered per browser. */
export function ThemeSwitch() {
  const [theme, setLocal] = useState<Theme>(() =>
    typeof document === 'undefined' ? 'light' : getTheme(),
  )
  const isDark = theme === 'dark'

  const toggle = () => {
    const next: Theme = isDark ? 'light' : 'dark'
    setTheme(next)
    setLocal(next)
  }

  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
      title={isDark ? 'Light mode' : 'Dark mode'}
      className="grid size-10 place-items-center rounded-panel text-white/80 transition-colors hover:bg-white/10 hover:text-white"
    >
      {isDark ? <Sun size={20} aria-hidden /> : <Moon size={20} aria-hidden />}
    </button>
  )
}
