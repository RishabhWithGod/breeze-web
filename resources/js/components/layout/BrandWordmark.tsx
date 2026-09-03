import breezeLogoFull from '@/assets/breeze-logo-full.png'
import { cn } from '@/utils'

export interface BrandWordmarkProps {
  className?: string
}

/** The brand mark as shown on the auth screens: the full lockup, as supplied. */
export function BrandWordmark({ className }: BrandWordmarkProps) {
  return (
    <img
      src={breezeLogoFull}
      alt="Breeze AI"
      className={cn('h-16 w-auto object-contain', className)}
    />
  )
}
