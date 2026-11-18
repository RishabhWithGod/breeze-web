import { useEffect, useState } from 'react'
import { Menu, X } from 'lucide-react'
import { useUiStore } from '@/store'
import { cn } from '@/utils'
import { Logo } from './Logo'
import { NotificationsMenu } from './NotificationsMenu'
import { ProfileMenu } from './ProfileMenu'
import { ThemeSwitch } from './ThemeSwitch'
import { TimerIndicator } from './TimerIndicator'

/**
 * Fixed application header. Becomes opaque once the page is scrolled, matching
 * the reference's `scrolled-fixed-top` behaviour.
 */
export function Navbar() {
  const [isScrolled, setIsScrolled] = useState(false)
  const { toggleSidebar, isSidebarOpen } = useUiStore()

  useEffect(() => {
    const onScroll = () => setIsScrolled(window.scrollY > 8)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  return (
    <header
      className={cn(
        // Starts where the rail ends on desktop (the rail carries the logo and
        // the company), and spans the full width on phones and tablets.
        'fixed top-0 right-0 left-0 z-60 h-navbar border-b backdrop-blur-xl backdrop-saturate-150 transition-shadow duration-300 lg:left-sidebar',
        'bg-surface-glass/75 border-hairline',
        isScrolled && 'shadow-panel',
      )}
    >
      <div className="flex h-full items-center gap-3 px-4 sm:gap-5 sm:px-6 xl:px-10">
        <button
          type="button"
          onClick={toggleSidebar}
          aria-label={isSidebarOpen ? 'Close navigation' : 'Open navigation'}
          aria-expanded={isSidebarOpen}
          className="grid size-10 shrink-0 place-items-center rounded-panel text-white transition-colors hover:bg-white/8 lg:hidden"
        >
          {isSidebarOpen ? <X size={24} aria-hidden /> : <Menu size={24} aria-hidden />}
        </button>

        {/* The lockup lives in the rail on desktop; phones have no rail to hold it. */}
        <Logo className="shrink-0 lg:hidden" />

        <div className="ml-auto flex items-center gap-1 sm:gap-2">
          <TimerIndicator />
          <ThemeSwitch />
          <NotificationsMenu />
          <ProfileMenu />
        </div>
      </div>
    </header>
  )
}
