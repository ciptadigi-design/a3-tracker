export function resolveMemberBranchNames(member, branches) {
  const ids = Array.isArray(member?.branch_ids) ? member.branch_ids : []
  if (!ids.length) return []
  const nameById = new Map((branches ?? []).map((branch) => [branch.id, branch.name]))
  return ids.map((id) => nameById.get(id)).filter(Boolean)
}
