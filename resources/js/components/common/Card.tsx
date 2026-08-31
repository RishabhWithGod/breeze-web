import type { ReactNode } from 'react'
import { motion, type HTMLMotionProps } from 'framer-motion'
import { MOTION } from '@/constants'
import { cn } from '@/utils'

export type CardVariant = 'glass' | 'solid' | 'spotlight' | 'ocean' | 'outline'
export type CardPadding = 'none' | 'sm' | 'md' | 'lg'

const VARIANTS: Record<CardVariant, string> = {
  glass: 'glass border border-hairline',
  solid: 'glass-strong border border-hairline-strong',
  spotlight: 'grad-spotlight border border-hairline-strong',
  ocean: 'grad-ocean border border-steel-400 shadow-panel',
  outline: 'bg-transparent border border-hairline-strong',
}

const PADDINGS: Record<CardPadding, string> = {
  none: '',
  sm: 'p-4',
  md: 'p-5 sm:p-6',
  lg: 'p-6 sm:p-8 lg:p-10',
}

export interface CardProps extends Omit<HTMLMotionProps<'div'>, 'ref' | 'children'> {
  variant?: CardVariant
  padding?: CardPadding
  /** Adds a lift + border highlight on hover. */
  hoverable?: boolean
  /** Fade/slide the card in on mount. */
  animated?: boolean
  /** Entrance delay index, multiplied by the shared stagger interval. */
  index?: number
  children?: ReactNode
}

export function Card({
  variant = 'glass',
  padding = 'md',
  hoverable = false,
  animated = true,
  index = 0,
  className,
  children,
  ...props
}: CardProps) {
  return (
    <motion.div
      initial={animated ? { opacity: 0, y: 18 } : false}
      whileInView={animated ? { opacity: 1, y: 0 } : undefined}
      viewport={{ once: true, amount: 0.15 }}
      transition={{
        duration: MOTION.slow,
        delay: index * MOTION.stagger,
        ease: [0.22, 1, 0.36, 1],
      }}
      className={cn(
        'relative rounded-card',
        VARIANTS[variant],
        PADDINGS[padding],
        hoverable &&
          'transition-colors duration-300 hover:border-brand/50 hover:bg-white/10',
        className,
      )}
      {...(hoverable ? { whileHover: { y: -4 } } : {})}
      {...props}
    >
      {children}
    </motion.div>
  )
}

interface CardHeaderProps {
  title: ReactNode
  subtitle?: ReactNode
  /** Right-aligned actions — buttons, filters, menus. */
  actions?: ReactNode
  className?: string
}

export function CardHeader({ title, subtitle, actions, className }: CardHeaderProps) {
  return (
    <div
      className={cn(
        'mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between',
        className,
      )}
    >
      <div className="min-w-0">
        <h3 className="text-xl font-semibold text-white">{title}</h3>
        {subtitle && <p className="mt-1 text-md text-white/85">{subtitle}</p>}
      </div>
      {actions && <div className="flex shrink-0 items-center gap-3">{actions}</div>}
    </div>
  )
}

export function CardBody({
  className,
  children,
}: {
  className?: string
  children: ReactNode
}) {
  return <div className={cn('text-md text-white', className)}>{children}</div>
}

export function CardFooter({
  className,
  children,
}: {
  className?: string
  children: ReactNode
}) {
  return (
    <div
      className={cn(
        'mt-6 flex flex-wrap items-center gap-3 border-t border-hairline pt-5',
        className,
      )}
    >
      {children}
    </div>
  )
}
