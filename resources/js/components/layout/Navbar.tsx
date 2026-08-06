import { useEffect, useState } from 'react'
import { Menu, Search, X } from 'lucide-react'
import { SearchBox } from '@/components/common'
import { useUiStore } from '@/store'
import { cn } from '@/utils'
import { Logo } from './Logo'
import { NotificationsMenu } from './NotificationsMenu'
import { ProfileMenu } from './ProfileMenu'
import { ThemeToggle } from './ThemeToggle'

/**
 * Fixed application header. Becomes opaque once the page is scrolled, matching
 * the reference's `scrolled-fixed-top` behaviour.
 */
export function Navbar() {
  const [query, setQuery] = useState('')
  const [isScrolled, setIsScrolled] = useState(false)
  const { toggleSidebar, isSidebarOpen, isSearchOpen, setSearchOpen } = useUiStore()

  useEffect(() => {
    const onScroll = () => setIsScrolled(window.scrollY > 8)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  return (
    <header
      className={cn(
        'fixed inset-x-0 top-0 z-60 h-navbar border-b transition-colors duration-500',
        isScrolled
          ? 'grad-ocean border-hairline shadow-raised'
          : 'glass-strong border-transparent shadow-panel',
      )}
    >
      <div className="mx-auto flex h-full max-w-ultra items-center gap-3 px-4 sm:gap-5 sm:px-6">
        <button
          type="button"
          onClick={toggleSidebar}
          aria-label={isSidebarOpen ? 'Close navigation' : 'Open navigation'}
          aria-expanded={isSidebarOpen}
          className="grid size-10 shrink-0 place-items-center rounded-panel text-brand transition-colors hover:bg-white/10 lg:hidden"
        >
          {isSidebarOpen ? <X size={24} aria-hidden /> : <Menu size={24} aria-hidden />}
        </button>

        <Logo className="shrink-0" />

        <div className="hidden flex-1 justify-center md:flex">
          <SearchBox
            value={query}
            onValueChange={setQuery}
            placeholder="Search jobs, estimates, documents…"
            containerClassName="max-w-xl"
            aria-label="Global search"
          />
        </div>

        <div className="ml-auto flex items-center gap-1 sm:gap-2">
          <button
            type="button"
            onClick={() => setSearchOpen(!isSearchOpen)}
            aria-label="Toggle search"
            aria-expanded={isSearchOpen}
            className="grid size-10 place-items-center rounded-full text-white transition-colors hover:bg-white/10 hover:text-brand md:hidden"
          >
            <Search size={20} aria-hidden />
          </button>

          <ThemeToggle />
          <NotificationsMenu />
          <ProfileMenu />
        </div>
      </div>

      {/* Collapsible search row for small screens. */}
      {isSearchOpen && (
        <div className="glass-strong border-t border-hairline px-4 py-3 md:hidden">
          <SearchBox
            value={query}
            onValueChange={setQuery}
            placeholder="Search jobs, estimates, documents…"
            aria-label="Global search"
          />
        </div>
      )}
    </header>
  )
}
