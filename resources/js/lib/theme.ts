export type Theme = 'light' | 'dark'

const KEY = 'breeze.theme'

/** Stored choice, else the OS preference, else light. */
export function getTheme(): Theme {
  try {
    const stored = localStorage.getItem(KEY)
    if (stored === 'light' || stored === 'dark') return stored
  } catch {
    /* storage blocked */
  }
  try {
    if (window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark'
  } catch {
    /* no matchMedia */
  }
  return 'light'
}

export function setTheme(theme: Theme): void {
  document.documentElement.dataset['theme'] = theme
  try {
    localStorage.setItem(KEY, theme)
  } catch {
    /* storage blocked */
  }
}

export function applyStoredTheme(): void {
  document.documentElement.dataset['theme'] = getTheme()
}
