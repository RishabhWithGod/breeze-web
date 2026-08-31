import { cn } from '@/utils'

export interface SkeletonProps {
  className?: string
  /** Renders a pill instead of a rounded rectangle. */
  circle?: boolean
}

/** Shimmering placeholder block. */
export function Skeleton({ className, circle = false }: SkeletonProps) {
  return (
    <div
      aria-hidden
      className={cn(
        'shimmer bg-white/5',
        circle ? 'rounded-full' : 'rounded-panel',
        className,
      )}
    />
  )
}

/** Multi-line text placeholder. */
export function SkeletonText({
  lines = 3,
  className,
}: {
  lines?: number
  className?: string
}) {
  return (
    <div className={cn('space-y-2.5', className)}>
      {Array.from({ length: lines }, (_, index) => (
        <Skeleton
          key={index}
          className={cn('h-3.5', index === lines - 1 ? 'w-2/3' : 'w-full')}
        />
      ))}
    </div>
  )
}

/** Card-shaped placeholder used while dashboards load. */
export function SkeletonCard({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        'glass rounded-card border border-hairline p-6',
        className,
      )}
    >
      <div className="flex items-center gap-4">
        <Skeleton circle className="size-12" />
        <div className="flex-1 space-y-2">
          <Skeleton className="h-4 w-1/3" />
          <Skeleton className="h-3 w-1/2" />
        </div>
      </div>
      <SkeletonText className="mt-6" lines={3} />
    </div>
  )
}
