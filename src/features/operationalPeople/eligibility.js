export function isCounterOperatorForBranch(person, branchId) {
  if (!person?.is_active || !branchId) return false
  return (person.operational_person_branches ?? []).some((assignment) => assignment.branch_id === branchId && assignment.is_active && assignment.can_record_counter === true)
}

export function counterOperatorsForBranch(people, branchId) {
  return (people ?? []).filter((person) => isCounterOperatorForBranch(person, branchId))
}

export function branchOperationalPeople(people, branchId) {
  return (people ?? []).filter((person) => person?.is_active && (person.operational_person_branches ?? []).some((assignment) => assignment.branch_id === branchId && assignment.is_active))
}
