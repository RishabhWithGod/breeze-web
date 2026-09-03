import { Plus, Trash2 } from 'lucide-react'
import { AddressField } from './AddressField'
import { Button } from './Button'
import { IconButton } from './Button'
import { TextInput } from './Field'
import type { DraftAddress } from '@/types'
import { cn, emptyAddress } from '@/utils'

export interface AddressListFieldProps {
  addresses: readonly DraftAddress[]
  onChange: (addresses: DraftAddress[]) => void
  /** Field errors keyed as `addresses.0.address`, straight from the server. */
  errors?: Record<string, string>
  disabled?: boolean
  className?: string
}

/**
 * Every site a client has work at.
 *
 * A list rather than a field because most clients have more than one building,
 * and the old single box forced the other addresses to be dropped or crammed
 * into one line. The first row is the primary — it is what lists show, and what
 * a job defaults to — so its position is meaningful, not decorative.
 */
export function AddressListField({
  addresses,
  onChange,
  errors = {},
  disabled = false,
  className,
}: AddressListFieldProps) {
  const update = (index: number, patch: Partial<DraftAddress>) => {
    onChange(addresses.map((row, position) => (position === index ? { ...row, ...patch } : row)))
  }

  const remove = (index: number) => {
    onChange(addresses.filter((_, position) => position !== index))
  }

  return (
    <div className={cn('space-y-4', className)}>
      {addresses.map((row, index) => (
        <div
          key={index}
          className="rounded-panel border border-hairline bg-white/4 p-4"
        >
          <div className="mb-3 flex items-center justify-between gap-3">
            <p className="text-sm font-medium text-white">
              {index === 0 ? 'Primary site' : `Site ${index + 1}`}
            </p>
            {/* The last row cannot go: a client with no address at all is
                created by adding none, not by emptying the list. */}
            {addresses.length > 1 && (
              <IconButton
                icon={Trash2}
                label={`Remove site ${index + 1}`}
                variant="white"
                size="sm"
                disabled={disabled}
                onClick={() => remove(index)}
                className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
              />
            )}
          </div>

          <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <TextInput
              id={`address-label-${index}`}
              label="Name*"
              placeholder="e.g. Main building"
              value={row.label}
              disabled={disabled}
              onChange={(event) => update(index, { label: event.target.value })}
              {...(errors[`addresses.${index}.label`]
                ? { error: errors[`addresses.${index}.label`] }
                : {})}
            />

            <AddressField
              id={`address-${index}`}
              label="Address"
              placeholder="Start typing the site address"
              value={row.address}
              latitude={row.latitude}
              longitude={row.longitude}
              disabled={disabled}
              onChange={(address, latitude, longitude) =>
                update(index, { address, latitude, longitude })
              }
              {...(errors[`addresses.${index}.address`]
                ? { error: errors[`addresses.${index}.address`] }
                : {})}
            />
          </div>
        </div>
      ))}

      <Button
        type="button"
        variant="white"
        size="sm"
        leftIcon={Plus}
        disabled={disabled}
        onClick={() => onChange([...addresses, emptyAddress()])}
      >
        Add another site
      </Button>
    </div>
  )
}
