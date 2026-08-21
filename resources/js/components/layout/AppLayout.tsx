import type { ReactNode } from 'react'
import { usePage } from '@inertiajs/react'
import { useUiStore } from '@/store'
import { cn } from '@/utils'
import { Footer } from './Footer'
import { Navbar } from './Navbar'
import { RealtimeUserSync } from './RealtimeUserSync'
import { Sidebar } from './Sidebar'

/**
 * App shell: fixed header, responsive sidebar and a fluid content column that
 * scales from mobile through ultra-wide.
 *
 * Mounted as an Inertia persistent layout, so the header and rail keep their
 * state across visits while only the content column swaps.
 */
export function AppLayout({ children }: { children: ReactNode }) {
  const isSidebarCollapsed = useUiStore((state) => state.isSidebarCollapsed)
  const { url } = usePage()

  return (
    <div className="min-h-dvh pt-navbar">
      <RealtimeUserSync />
      <Navbar />
      <Sidebar />

      <div
        className={cn(
          'transition-[padding] duration-300 ease-out',
          isSidebarCollapsed ? 'lg:pl-20' : 'lg:pl-sidebar',
        )}
      >
        <main
          id="main-content"
          className="mx-auto flex min-h-[calc(100dvh-var(--spacing-navbar))] max-w-ultra flex-col px-4 py-6 sm:px-6 sm:py-8 xl:px-10"
        >
          <div className="flex-1">
            {/*
              Keyed on the URL so each visit mounts fresh and PageTransition
              replays its entrance.

              Deliberately not wrapped in `AnimatePresence mode="wait"`: waiting
              on the outgoing page's exit animation could strand the previous
              screen on screen after a POST that redirects elsewhere — the URL and
              props updated while the old markup stayed mounted. Entrance-only
              animation keeps navigation honest.
            */}
            <div key={url}>{children}</div>
          </div>

          <Footer />
        </main>
      </div>
    </div>
  )
}
