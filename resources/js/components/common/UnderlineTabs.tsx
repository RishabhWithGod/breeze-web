import { cn } from '@/utils'

export interface UnderlineTab<T extends string> {
  readonly key: T
  readonly label: string
  readonly count?: number
}

export interface UnderlineTabsProps<T extends string> {
  tabs: readonly UnderlineTab<T>[]
  value: T
  onChange: (key: T) => void
  className?: string
}

/** The Breeze app's tab strip: an underline under the current tab, a small count pill beside each label. */
export function UnderlineTabs<T extends string>({ tabs, value, onChange, className }: UnderlineTabsProps<T>) {
  return (
    <div role="tablist" className={cn('no-scrollbar mb-5 flex gap-0.5 overflow-x-auto border-b border-hairline', className)}>
      {tabs.map((tab) => {
        const on = tab.key === value

        return (
          <button
            key={tab.key}
            type="button"
            role="tab"
            aria-selected={on}
            onClick={() => onChange(tab.key)}
            className={cn(
              '-mb-px flex items-center gap-1.5 border-b-2 px-3.5 py-2.5 text-[14px] font-[550] whitespace-nowrap transition-colors',
              on
                ? 'border-brand text-white'
                : 'border-transparent text-[var(--text-3)] hover:text-white',
            )}
          >
            {tab.label}
            {typeof tab.count === 'number' && (
              <span
                className={cn(
                  'rounded-full px-[7px] text-[11.5px] leading-[1.5] font-[550]',
                  on ? 'bg-brand text-brand-ink' : 'bg-[var(--surface-3)] text-[var(--text-2)]',
                )}
              >
                {tab.count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}
