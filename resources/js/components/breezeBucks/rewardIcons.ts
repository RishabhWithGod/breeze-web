import { Award, Coffee, CreditCard, Gift, Ticket, type LucideIcon } from 'lucide-react'

const ICON_MAP: Record<string, LucideIcon> = {
  gift: Gift,
  'credit-card': CreditCard,
  coffee: Coffee,
  award: Award,
  ticket: Ticket,
}

export const REWARD_ICON_OPTIONS = Object.keys(ICON_MAP)

export function rewardIconFor(icon: string | null): LucideIcon {
  return (icon && ICON_MAP[icon]) || Gift
}
