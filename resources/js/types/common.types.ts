import type { LucideIcon } from 'lucide-react'

/** Visual weight shared by buttons, badges and chips. */
export type Variant =
  | 'primary'
  | 'blue'
  | 'purple'
  | 'secondary'
  | 'dark'
  | 'ghost'
  | 'outline'
  | 'danger'
  | 'white'

export type Size = 'sm' | 'md' | 'lg'

export type Tone =
  | 'brand'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'purple'
  | 'blue'
  | 'neutral'

export interface NavItem {
  readonly label: string
  /** Absolute path — Inertia visits it, no page reload. */
  readonly href: string
  readonly icon: LucideIcon
  /** Optional counter rendered as a pill on the right of the item. */
  readonly badge?: number
  /** Sub-items shown nested under this one — see Estimates → Addendum. Every other entry leaves this unset and renders exactly as before. */
  readonly children?: readonly NavItem[]
  /** Roles (lowercase) this entry is shown to; unset shows it to everyone. */
  readonly roles?: readonly string[]
  /** The permission the signed-in role needs to see this entry (Roles & Permissions); unset shows it to everyone. */
  readonly permission?: string
  /** A feature switch that has to be on for this entry to show — see `config/features.php`. */
  readonly feature?: 'estimateBuilder'
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
