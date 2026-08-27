const channelBySuffix = {
  C: 'cyan',
  M: 'magenta',
  Y: 'yellow',
  K: 'black',
}

const channelByName = {
  CYAN: 'cyan',
  MAGENTA: 'magenta',
  YELLOW: 'yellow',
  BLACK: 'black',
}

function channelFromIdentifier(value) {
  const normalized = value?.trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_|_$/g, '')
  const suffix = normalized?.match(/_([CMYK])$/)?.[1]
  return channelBySuffix[suffix] ?? null
}

function channelFromName(name) {
  const color = name?.trim().toUpperCase().match(/(?:^|[\s/_-])(CYAN|MAGENTA|YELLOW|BLACK)$/)?.[1]
  return channelByName[color] ?? null
}

export function getComponentChannel({ slotCode, code, name } = {}) {
  return channelFromIdentifier(slotCode) ?? channelFromIdentifier(code) ?? channelFromName(name)
}
