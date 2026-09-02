import type { LucideIcon } from 'lucide-react'

/** Visual weight shared by buttons, badges and chips. */
export type Variant =
  | 'primary'
  | 'secondary'
  | 'dark'
  | 'ghost'
  | 'outline'
  | 'danger'
  | 'white'

export type Size = 'sm' | 'md' | 'lg'

export type Tone = 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'neutral'

export interface NavItem {
  readonly label: string
  /** Absolute path — Inertia visits it, no page reload. */
  readonly href: string
  readonly icon: LucideIcon
  /** Optional counter rendered as a pill on the right of the item. */
  readonly badge?: number
}

export interface BreadcrumbItem {
  readonly label: string
  readonly href?: string
}

export interface TableColumn<T> {
  readonly key: string
  /** Usually a label, but a control when the column is one — a select-all box. */
  readonly header: React.ReactNode
  readonly align?: 'left' | 'center' | 'right'
  /** Tailwind width utility, e.g. `w-40`. */
  readonly width?: string
  readonly render: (row: T, index: number) => React.ReactNode
}

export interface SelectOption {
  readonly label: string
  readonly value: string
}
