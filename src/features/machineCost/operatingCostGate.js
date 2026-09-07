/**
 * Advanced Operating Costs remain a deferred, account-gated feature. When the
 * account flag is off the Operating Costs tab must be hidden entirely rather
 * than showing an empty/disabled panel, and any stale view state pointing at
 * it must fail safely back to Summary instead of rendering a broken tab.
 */
export function resolveMachineCostTab(requestedView, advancedEnabled) {
  return requestedView === 'operating' && advancedEnabled ? 'operating' : 'summary'
}
