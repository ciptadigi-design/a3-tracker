export function formatCounter(value) {
  if (value == null || value === '') return '—'
  return new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 }).format(Number(value))
}

export function formatUsage(value) {
  if (value == null) return 'Baseline'
  return `+${formatCounter(value)}`
}

export function toLocalDateTimeInput(date = new Date()) {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 16)
}

export function dateKey(date, timezone) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date)
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${values.year}-${values.month}-${values.day}`
}

export function calculateDailySummary(history, timezone, now = new Date()) {
  // "Today's Usage" is the sum of database-derived deltas for effective
  // readings whose observed date falls on today in the machine timezone.
  // A first-ever baseline contributes no usage until a later reading exists.
  const effective = history.filter((reading) => reading.status === 'effective')
  const today = dateKey(now, timezone)
  const todayReadings = effective.filter((reading) => dateKey(new Date(reading.observed_at), timezone) === today)
  const knownUsage = todayReadings.map((reading) => reading.usage).filter((usage) => usage != null)
  return {
    lastReading: effective[0] ?? null,
    todayEntryCount: todayReadings.length,
    todayUsage: knownUsage.length ? knownUsage.reduce((sum, usage) => sum + Number(usage), 0) : null,
  }
}

export function mapCounterError(error) {
  const message = `${error?.cause?.message ?? ''} ${error?.message ?? ''} ${error?.details ?? ''}`.trim()
  if (message.includes('counter regression')) return 'The new counter is lower than the previous effective reading. Check the machine display and try again.'
  if (message.includes('older than the latest')) return 'The observed time is earlier than the latest effective reading. Counter entries must remain chronological.'
  if (message.includes('cannot be in the future')) return 'Observed time cannot be more than five minutes in the future.'
  if (message.includes('zero or greater')) return 'Counter value must be zero or greater.'
  if (message.includes('decimal places')) return 'Total Impressions must be entered as a whole number.'
  if (message.includes('client request id')) return 'This submission was already processed with different values. Refresh the history before trying again.'
  if (message.includes('only an effective reading can be corrected')) return 'This reading has already been corrected or voided and can no longer be changed.'
  if (message.includes('chronological neighbour')) return 'This correction would make the counter go backwards against a nearby reading. Adjust the value or the effective date and time.'
  if (error?.status === 404) return 'This reading is no longer available. Refresh the history and try again.'
  if (error?.code === '42501' || error?.status === 403) return 'Your account role does not allow this counter action.'
  if (error?.code === 'P0002') return 'The selected machine or counter type is no longer available.'
  return error?.message || 'The counter action could not be completed. Check your connection and try again.'
}
