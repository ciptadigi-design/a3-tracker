// Presentation only. Every API mutation independently authorizes the action and resource.
export function capabilitiesForAccount(data, userId, accountId) {
  if (!data || data.contextUserId !== userId || !accountId) return {}
  const capabilities = data.capabilities?.[accountId]
  return capabilities && typeof capabilities === 'object' && !Array.isArray(capabilities) ? capabilities : {}
}

export function hasCapability(capabilities, key) {
  return capabilities?.[key] === true
}

// Ignore a response from a superseded refresh or a previous authenticated user.
export function createTenantContextLoader(load) {
  let generation = 0
  return {
    invalidate() { generation += 1 },
    async refresh(userId, publish, onError = () => {}, onSettled = () => {}) {
      const request = ++generation
      try {
        const data = await load(userId)
        if (request !== generation) return false
        publish({ ...data, contextUserId: userId })
        return true
      } catch (error) {
        if (request === generation) onError(error)
        return false
      } finally {
        if (request === generation) onSettled()
      }
    },
  }
}
