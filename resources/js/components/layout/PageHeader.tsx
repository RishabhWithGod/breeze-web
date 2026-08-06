import type { ReactNode } from 'react'
import { Breadcrumb, SectionHeading } from '@/components/common'
import type { BreadcrumbItem } from '@/types'
import { cn } from '@/utils'

export interface PageHeaderProps {
  title: string
  subtitle?: string
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
    <div className={cn('mb-8', className)}>
      {breadcrumbs && breadcrumbs.length > 0 && (
        <Breadcrumb items={breadcrumbs} className="mb-4" />
      )}
      <SectionHeading
        title={title}
        {...(subtitle ? { subtitle } : {})}
        {...(actions ? { actions } : {})}
      />
    </div>
  )
}
