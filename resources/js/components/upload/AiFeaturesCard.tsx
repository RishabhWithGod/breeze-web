import { motion } from 'framer-motion'
import { Check, Clock3 } from 'lucide-react'
import { Card, CardHeader } from '@/components/common'
import { AI_FEATURES, MOTION, PROCESSING_TIME_HINT } from '@/constants'

/** Marketing-style capability list shown beside the dropzone. */
export function AiFeaturesCard({ index }: { index?: number }) {
  return (
    <Card {...(index !== undefined ? { index } : {})}>
      <CardHeader title="AI Takeoff Features" />

      <ul className="space-y-4">
        {AI_FEATURES.map((feature, featureIndex) => (
          <motion.li
            key={feature}
            initial={{ opacity: 0, x: -10 }}
            whileInView={{ opacity: 1, x: 0 }}
            viewport={{ once: true }}
            transition={{
              duration: MOTION.base,
              delay: featureIndex * MOTION.stagger,
            }}
            className="flex items-start gap-3 text-md text-white"
          >
            <span className="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-brand/20 text-brand">
              <Check size={13} strokeWidth={3} aria-hidden />
            </span>
            {feature}
          </motion.li>
        ))}
      </ul>

      <p className="mt-6 flex items-center gap-3 rounded-panel bg-surface-blue p-4 text-md text-white">
        <Clock3 size={18} aria-hidden className="shrink-0 text-brand" />
        {PROCESSING_TIME_HINT}
      </p>
    </Card>
  )
}
