import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { Link } from '@inertiajs/react'
import type { InertiaLinkProps } from '@inertiajs/react'
import { Loader2, type LucideIcon } from 'lucide-react'
import type { Size, Variant } from '@/types'
import { cn } from '@/utils'
import { ICON_BUTTON_SIZES, buttonStyles, type ButtonStyleProps } from './buttonStyles'

export interface ButtonProps
  extends ButtonHTMLAttributes<HTMLButtonElement>,
    ButtonStyleProps {
  isLoading?: boolean
  leftIcon?: LucideIcon
  rightIcon?: LucideIcon
  children?: ReactNode
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'primary',
    size = 'md',
    fullWidth,
    isLoading = false,
    leftIcon: LeftIcon,
    rightIcon: RightIcon,
    disabled,
    className,
    children,
    ...props
  },
  ref,
) {
  const iconSize = size === 'sm' ? 15 : 18

  return (
    <button
      ref={ref}
      disabled={disabled ?? isLoading}
      aria-busy={isLoading || undefined}
      className={buttonStyles({ variant, size, fullWidth, className })}
      {...props}
    >
      {isLoading ? (
        <Loader2 size={iconSize} className="animate-spin" aria-hidden />
      ) : (
        LeftIcon && <LeftIcon size={iconSize} aria-hidden />
      )}
      {children}
      {RightIcon && !isLoading && <RightIcon size={iconSize} aria-hidden />}
    </button>
  )
})

// `size` is omitted because an anchor's own `size` attribute is numeric and
// would collide with the design system's size scale.
export interface ButtonLinkProps extends Omit<InertiaLinkProps, 'size'>, ButtonStyleProps {
  leftIcon?: LucideIcon
  rightIcon?: LucideIcon
}

/** Inertia link rendered with button styling. */
export function ButtonLink({
  variant = 'primary',
  size = 'md',
  fullWidth,
  className,
  leftIcon: LeftIcon,
  rightIcon: RightIcon,
  children,
  ...props
}: ButtonLinkProps) {
  const iconSize = size === 'sm' ? 15 : 18

  return (
    <Link className={buttonStyles({ variant, size, fullWidth, className })} {...props}>
      {LeftIcon && <LeftIcon size={iconSize} aria-hidden />}
      {children}
      {RightIcon && <RightIcon size={iconSize} aria-hidden />}
    </Link>
  )
}

export interface IconButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  icon: LucideIcon
  /** Required — icon-only controls must expose an accessible name. */
  label: string
  variant?: Variant
  size?: Size
}

export function IconButton({
  icon: Icon,
  label,
  variant = 'ghost',
  size = 'md',
  className,
  ...props
}: IconButtonProps) {
  const config = ICON_BUTTON_SIZES[size]

  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      className={cn(
        buttonStyles({ variant, size }),
        'rounded-full p-0',
        config.box,
        className,
      )}
      {...props}
    >
      <Icon size={config.icon} aria-hidden />
    </button>
  )
}
