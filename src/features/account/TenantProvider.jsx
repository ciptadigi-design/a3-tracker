import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAuth } from '../auth/useAuth.js'
import { loadTenantContext } from '../../services/tenant.js'
import { LoadingScreen } from '../../components/ui/LoadingScreen.jsx'
import { ErrorState } from '../../components/ui/ErrorState.jsx'
import { TenantContext } from './tenantContext.js'
import { reportFailure, userErrorMessage } from '../../lib/appErrors.js'
import { readBranchPreference, resolveAuthorizedBranchId, writeBranchPreference } from './branchPreference.js'

export function TenantProvider({ children }) {
  const { user } = useAuth()
  const [tenantData, setTenantData] = useState(null)
  const [selectedAccountId, setSelectedAccountId] = useState(null)
  const [selectedBranchId, setSelectedBranchId] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const refresh = useCallback(async () => {
    if (!user) return
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadTenantContext(user.id)
      setTenantData(data)
      setSelectedAccountId((current) => data.accounts.some((account) => account.id === current) ? current : data.accounts[0]?.id ?? null)
    } catch (loadError) {
      reportFailure(loadError, { operation: 'tenant.load' })
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [user])

  useEffect(() => { refresh() }, [refresh])

  const account = tenantData?.accounts.find((item) => item.id === selectedAccountId) ?? null
  const availableBranches = useMemo(
    () => tenantData?.branches.filter((branch) => branch.account_id === selectedAccountId) ?? [],
    [selectedAccountId, tenantData],
  )

  useEffect(() => {
    if (!user?.id || !selectedAccountId || !availableBranches.length) return
    setSelectedBranchId((current) => {
      const next = resolveAuthorizedBranchId({
        currentBranchId: current,
        preferredBranchId: readBranchPreference({ userId: user.id, accountId: selectedAccountId }),
        branches: availableBranches,
      })
      if (next) writeBranchPreference({ userId: user.id, accountId: selectedAccountId, branchId: next })
      return next
    })
  }, [availableBranches, selectedAccountId, user?.id])

  const selectBranch = useCallback((branchId) => {
    if (!user?.id || !selectedAccountId || !availableBranches.some((branch) => branch.id === branchId && branch.is_active !== false)) return false
    setSelectedBranchId(branchId)
    writeBranchPreference({ userId: user.id, accountId: selectedAccountId, branchId })
    return true
  }, [availableBranches, selectedAccountId, user?.id])

  const branch = availableBranches.find((item) => item.id === selectedBranchId) ?? null
  const membership = useMemo(() => tenantData?.memberships.find((item) => item.account_id === selectedAccountId)
    ?? (tenantData?.isPlatformSuperuser ? { account_id: selectedAccountId, role: 'platform_superuser', status: 'active' } : null),
  [selectedAccountId, tenantData])
  const operationalPermissions = tenantData?.permissions?.find((item) => item.account_id === selectedAccountId) ?? null
  const value = useMemo(() => ({
    profile: tenantData?.profile ?? null,
    accounts: tenantData?.accounts ?? [],
    branches: availableBranches,
    account,
    branch,
    membership,
    operationalPermissions,
    selectedAccountId,
    selectedBranchId,
    setSelectedAccountId,
    setSelectedBranchId: selectBranch,
    refresh,
    isPlatformSuperuser: Boolean(tenantData?.isPlatformSuperuser),
  }), [account, availableBranches, branch, membership, operationalPermissions, refresh, selectBranch, selectedAccountId, selectedBranchId, tenantData])

  if (isLoading) return <LoadingScreen label="Loading your account and branches" />
  if (error) return <ErrorState title="We couldn't load your workspace" detail={userErrorMessage(error, 'Workspace context is temporarily unavailable.')} onRetry={refresh} />
  if (!tenantData?.accounts.length) return <ErrorState title="No active workspace found" detail="Your account is authenticated, but it does not have an active account membership." />
  if (!availableBranches.length) return <ErrorState title="No active Branch access" detail="This workspace has no active Branch available to your membership. Contact a workspace Owner or Platform Superuser." onRetry={refresh} />
  if (!branch) return <LoadingScreen label="Selecting your active Branch" />

  return <TenantContext.Provider value={value}>{children}</TenantContext.Provider>
}
