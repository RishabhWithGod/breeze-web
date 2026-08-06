import { motion } from 'framer-motion'
import { BrainCircuit } from 'lucide-react'
import { cn } from '@/utils'

export interface ProcessingVisualProps {
  /** Pauses the animation when the pipeline is not running. */
  isActive?: boolean
  className?: string
}

/** Animated AI badge shown at the top of the processing page. */
export function ProcessingVisual({
  isActive = true,
  className,
}: ProcessingVisualProps) {
  return (
    <div className={cn('relative mx-auto grid size-28 place-items-center', className)}>
      <span
        className={cn(
          'absolute inset-0 rounded-full bg-brand/20',
          isActive && 'animate-pulse-ring',
        )}
        aria-hidden
      />
      <span
        className={cn(
          'absolute inset-3 rounded-full bg-brand/15',
          isActive && 'animate-pulse-ring',
        )}
        aria-hidden
      />

      <motion.span
        animate={isActive ? { y: [0, -8, 0] } : { y: 0 }}
        transition={{ duration: 3.2, repeat: isActive ? Infinity : 0, ease: 'easeInOut' }}
        className="relative grid size-20 place-items-center rounded-full grad-midnight ring-1 ring-brand/50"
      >
        <BrainCircuit size={38} aria-hidden className="text-brand-soft" />
      </motion.span>
    </div>
  )
}
