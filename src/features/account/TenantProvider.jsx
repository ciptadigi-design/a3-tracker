import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAuth } from '../auth/useAuth.js'
import { loadTenantContext } from '../../services/supabase/tenant.js'
import { LoadingScreen } from '../../components/ui/LoadingScreen.jsx'
import { ErrorState } from '../../components/ui/ErrorState.jsx'
import { TenantContext } from './tenantContext.js'
import { reportFailure, userErrorMessage } from '../../lib/appErrors.js'

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
    setSelectedBranchId((current) => availableBranches.some((branch) => branch.id === current) ? current : availableBranches[0]?.id ?? null)
  }, [availableBranches])

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
    setSelectedBranchId,
    refresh,
    isPlatformSuperuser: Boolean(tenantData?.isPlatformSuperuser),
  }), [account, availableBranches, branch, membership, operationalPermissions, refresh, selectedAccountId, selectedBranchId, tenantData])

  if (isLoading) return <LoadingScreen label="Loading your account and branches" />
  if (error) return <ErrorState title="We couldn't load your workspace" detail={userErrorMessage(error, 'Workspace context is temporarily unavailable.')} onRetry={refresh} />
  if (!tenantData?.accounts.length) return <ErrorState title="No active workspace found" detail="Your account is authenticated, but it does not have an active account membership." />
  if (!availableBranches.length) return <ErrorState title="No active Branch access" detail="This workspace has no active Branch available to your membership. Contact a workspace Owner or Platform Superuser." onRetry={refresh} />
  if (!branch) return <LoadingScreen label="Selecting your active Branch" />

  return <TenantContext.Provider value={value}>{children}</TenantContext.Provider>
}
