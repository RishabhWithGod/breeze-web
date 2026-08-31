import type { ReactNode } from 'react'
import { AppLayout } from './AppLayout'

/**
 * Wraps a page in the app shell. Assign to a page's `layout` property:
 *
 *   Home.layout = appLayout
 *
 * Kept in its own module so AppLayout.tsx exports nothing but components, which
 * is what Fast Refresh needs in order to hot-reload the shell.
 */
export const appLayout = (page: ReactNode) => <AppLayout>{page}</AppLayout>
