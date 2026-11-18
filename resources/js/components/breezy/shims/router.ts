// Stand-in for react-router-dom: Breezy only reads the path and navigates.
import { router, usePage } from '@inertiajs/react'

export function useLocation(): { pathname: string; search: string; hash: string } {
  const { url } = usePage()
  const [path = '/', query = ''] = url.split('?')
  return { pathname: path, search: query ? `?${query}` : '', hash: '' }
}

export function useNavigate(): (to: string | number) => void {
  return (to) => {
    if (typeof to === 'number') window.history.go(to)
    else router.visit(to)
  }
}
