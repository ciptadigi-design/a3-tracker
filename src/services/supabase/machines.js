import { supabase } from './client.js'

const machineFields = `
  id,
  account_id,
  branch_id,
  machine_code,
  display_name,
  serial_number,
  installed_on,
  status,
  timezone,
  is_active,
  machine_models (
    id,
    model_code,
    name,
    color_capability,
    manufacturers (id, code, name)
  )
`

export async function loadMachines({ accountId, branchId }) {
  let query = supabase
    .from('machines')
    .select(machineFields)
    .eq('account_id', accountId)
    .order('display_name')

  if (branchId) query = query.eq('branch_id', branchId)

  const { data, error } = await query
  if (error) throw error
  return data ?? []
}
