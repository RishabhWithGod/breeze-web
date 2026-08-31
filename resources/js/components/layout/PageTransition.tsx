import type { ReactNode } from 'react'
import { motion } from 'framer-motion'
import { MOTION } from '@/constants'

/** Wraps route content so entering/leaving pages cross-fade consistently. */
export function PageTransition({ children }: { children: ReactNode }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -12 }}
      transition={{ duration: MOTION.base, ease: [0.22, 1, 0.36, 1] }}
    >
      {children}
    </motion.div>
  )
}
