import { useEffect, useRef, useState } from 'react'
import { animate, useReducedMotion } from 'motion/react'
import { shouldAnimateNumber } from './liveDisplayModel.js'

/**
 * Count-up transition from the previously shown value to a new one. Only
 * animates when `value` actually changed from what was last rendered - a
 * poll that returns the same number, or any unrelated re-render, must not
 * replay the transition. `prefers-reduced-motion` (or an explicit reduced
 * motion setting) snaps straight to the new value instead of animating it.
 */
export function AnimatedNumber({ value, format, durationMs = 800 }) {
  const previousValueRef = useRef(value)
  // Only holds a value while an animation is actively running - the
  // pass-through (no-transition) case renders `value` directly during
  // render instead of mirroring it into state from inside an effect.
  const [animatedValue, setAnimatedValue] = useState(null)
  const reducedMotion = useReducedMotion()

  useEffect(() => {
    const previous = previousValueRef.current
    previousValueRef.current = value

    if (!shouldAnimateNumber({ previous, next: value, reducedMotion })) return undefined

    const controls = animate(previous, value, {
      duration: durationMs / 1000,
      ease: 'easeOut',
      onUpdate: (latest) => setAnimatedValue(latest),
    })
    return () => controls.stop()
  }, [value, durationMs, reducedMotion])

  const rounded = animatedValue == null ? value : Math.round(animatedValue)
  return <span>{format ? format(rounded) : rounded}</span>
}
