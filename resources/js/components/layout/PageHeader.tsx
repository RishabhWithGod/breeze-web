import type { ReactNode } from 'react'
import { Breadcrumb, SectionHeading } from '@/components/common'
import type { BreadcrumbItem } from '@/types'
import { cn } from '@/utils'

export interface PageHeaderProps {
  title: ReactNode
  subtitle?: ReactNode
  breadcrumbs?: readonly BreadcrumbItem[]
  actions?: ReactNode
  className?: string
}

/** Standard page intro: breadcrumb trail, title, subtitle and actions. */
export function PageHeader({
  title,
  subtitle,
  breadcrumbs,
  actions,
  className,
}: PageHeaderProps) {
  return (
    <div className={cn('mb-6', className)}>
      <SectionHeading
        as="h1"
        title={title}
        {...(breadcrumbs && breadcrumbs.length > 0
          ? { above: <Breadcrumb items={breadcrumbs} className="mb-2" /> }
          : {})}
        {...(subtitle ? { subtitle } : {})}
        {...(actions ? { actions } : {})}
      />
    </div>
  )
}
