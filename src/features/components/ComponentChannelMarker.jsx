import { getComponentChannel } from './componentChannel.js'

export function ComponentChannelMarker({ slotCode, code, name }) {
  const channel = getComponentChannel({ slotCode, code, name })
  if (!channel) return null

  return <span className={`component-channel-marker component-channel-${channel}`} aria-hidden="true" />
}
