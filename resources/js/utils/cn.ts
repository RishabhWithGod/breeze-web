import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

/**
 * Conditional class names with conflict-safe Tailwind merging.
 * Always use this instead of template strings so variant props can override
 * base classes predictably.
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs))
}
