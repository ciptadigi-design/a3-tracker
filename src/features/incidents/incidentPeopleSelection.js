export function selectIncidentOperator(current, person) {
  const shouldFollowOperator = !current.responsiblePersonTouched
    && (!current.responsiblePersonId || current.responsiblePersonId === current.operatorPersonId)
  return {
    ...current,
    operatorPersonId: person?.id ?? '',
    operatorName: person?.name ?? '',
    ...(shouldFollowOperator ? {
      responsiblePersonId: person?.id ?? '',
      responsibleName: person?.name ?? '',
      responsiblePersonTouched: false,
    } : {}),
  }
}

export function selectIncidentResponsiblePerson(current, person) {
  return {
    ...current,
    responsiblePersonId: person?.id ?? '',
    responsibleName: person?.name ?? '',
    responsiblePersonTouched: true,
  }
}

export function revalidateIncidentPeople(current, eligiblePeople) {
  const byId = new Map(eligiblePeople.map((person) => [person.id, person]))
  const operator = byId.get(current.operatorPersonId)
  const responsible = byId.get(current.responsiblePersonId)
  return {
    ...current,
    operatorPersonId: operator?.id ?? '',
    operatorName: operator?.name ?? '',
    responsiblePersonId: responsible?.id ?? '',
    responsibleName: responsible?.name ?? '',
    responsiblePersonTouched: responsible ? current.responsiblePersonTouched : false,
  }
}
