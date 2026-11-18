import { cn } from '@/utils'

const COLORS = ['#2563eb', '#0891b2', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#9333ea']

const initialsOf = (name: string) =>
  name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word.charAt(0).toUpperCase())
    .join('')

export interface AvatarPerson {
  readonly id?: number | string
  readonly name: string
  readonly initials?: string | null
}

/**
 * A person as a coloured circle of initials. On its own the colour follows the name; inside a stack
 * the caller passes `colorIndex`, so neighbours always differ.
 */
export function Avatar({
  person,
  colorIndex,
  className,
}: {
  person: AvatarPerson
  colorIndex?: number
  className?: string
}) {
  const color =
    COLORS[
      colorIndex !== undefined
        ? colorIndex % COLORS.length
        : [...person.name].reduce((sum, ch) => sum + ch.charCodeAt(0), 0) % COLORS.length
    ]

  return (
    <span
      title={person.name}
      style={{ background: color }}
      className={cn(
        'inline-grid size-[30px] shrink-0 place-items-center rounded-full text-[11.5px] font-semibold text-brand-ink',
        className,
      )}
    >
      {person.initials || initialsOf(person.name)}
    </span>
  )
}

/**
 * Overlapping avatars, the first few and a "+n" for the rest — as the Breeze app's team column draws them.
 * Coloured by position down the app's palette (blue, cyan, violet, pink, orange, green…), so a team reads
 * as a row of distinct people rather than as whatever their names happen to hash to.
 */
export function AvatarStack({ people, max = 4 }: { people: readonly AvatarPerson[]; max?: number }) {
  if (people.length === 0) return <span className="text-[var(--text-3)]">—</span>

  return (
    <span className="flex">
      {people.slice(0, max).map((person, index) => (
        <Avatar
          key={person.id ?? person.name}
          person={person}
          colorIndex={index}
          className={cn('border-2 border-[var(--surface)]', index > 0 && '-ml-2')}
        />
      ))}
      {people.length > max && (
        <span className="-ml-2 inline-grid size-[30px] shrink-0 place-items-center rounded-full border-2 border-[var(--surface)] bg-[#94a3b8] text-[11.5px] font-semibold text-brand-ink">
          +{people.length - max}
        </span>
      )}
    </span>
  )
}
