import { useEffect, useState } from 'react'
import { usePage } from '@inertiajs/react'
import { Menu, X } from 'lucide-react'
import { useUiStore } from '@/store'
import type { SharedPageProps } from '@/types'
import { cn } from '@/utils'
import { CompanyMark } from './CompanyMark'
import { Logo } from './Logo'
import { NotificationsMenu } from './NotificationsMenu'
import { ProfileMenu } from './ProfileMenu'
import { TimerIndicator } from './TimerIndicator'

/**
 * Fixed application header. Becomes opaque once the page is scrolled, matching
 * the reference's `scrolled-fixed-top` behaviour.
 */
export function Navbar() {
  const [isScrolled, setIsScrolled] = useState(false)
  const { toggleSidebar, isSidebarOpen } = useUiStore()
  const { company } = usePage<SharedPageProps>().props

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
        isScrolled ? 'grad-ocean border-hairline shadow-raised' : 'glass-strong border-transparent shadow-panel',
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

        {/* Whose workspace this is: the company's logo, or an icon until one is uploaded. */}
        {company && (
          <div className="flex min-w-0 items-center gap-3 border-l border-hairline pl-3 sm:pl-5">
            <CompanyMark logoUrl={company.logoUrl} name={company.name} size={36} />
            <span className="hidden max-w-48 truncate text-md font-semibold text-white md:block">{company.name}</span>
          </div>
        )}

        <div className="ml-auto flex items-center gap-1 sm:gap-2">
          <TimerIndicator />
          <NotificationsMenu />
          <ProfileMenu />
        </div>
      </div>
    </header>
  )
}
