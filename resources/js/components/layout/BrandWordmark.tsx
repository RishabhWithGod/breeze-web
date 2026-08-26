import { cn } from '@/utils'

export interface BrandWordmarkProps {
  className?: string
}

/**
 * The "Breeze AI" wordmark as it appears on the sign-in screen: two
 * stacked italic words, "Breeze" dark with a chrome edge and "AI" filled
 * with the same chrome, each led by a small cyan chevron.
 *
 * Drawn as an inline SVG rather than an image so it stays crisp at any size and
 * ships no extra request. Swap in the brand artwork here if the original file
 * becomes available — nothing else references the shape.
 */
export function BrandWordmark({ className }: BrandWordmarkProps) {
  return (
    <svg
      viewBox="0 0 300 132"
      role="img"
      aria-label="Breeze AI"
      className={cn('h-auto w-47.5', className)}
    >
      <defs>
        {/* Polished-metal ramp: highlight, cyan body, shadow, then a lift. */}
        <linearGradient id="brand-chrome" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="#f2fdff" />
          <stop offset="30%" stopColor="#7fe4fb" />
          <stop offset="52%" stopColor="#2ba9cf" />
          <stop offset="72%" stopColor="#1b6f92" />
          <stop offset="100%" stopColor="#cdf3ff" />
        </linearGradient>
      </defs>

      <g
        fontFamily="var(--font-sans)"
        fontSize="62"
        fontWeight="900"
        fontStyle="italic"
        letterSpacing="-2"
      >
        {/* Chevrons, sitting tight against the initial of each word. */}
        <path d="M20 26 L4 44 L20 62 L20 50 L12 44 L20 38 Z" fill="#2ba9cf" />
        <path d="M20 84 L4 102 L20 120 L20 108 L12 102 L20 96 Z" fill="#2ba9cf" />

        {/* Dark face, chrome edge. */}
        <text
          x="22"
          y="58"
          fill="#050a12"
          stroke="url(#brand-chrome)"
          strokeWidth="2.5"
          paintOrder="stroke"
        >
          Breeze
        </text>

        {/* Chrome face, dark edge. */}
        <text
          x="22"
          y="116"
          fill="url(#brand-chrome)"
          stroke="#050a12"
          strokeWidth="2.5"
          paintOrder="stroke"
        >
          AI
        </text>
      </g>
    </svg>
  )
}
