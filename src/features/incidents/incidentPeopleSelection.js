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

export function revalidateIncidentPeople(current, { operatorPeople, picPeople }) {
  const operator = new Map((operatorPeople ?? []).map((person) => [person.id, person])).get(current.operatorPersonId)
  const responsible = new Map((picPeople ?? []).map((person) => [person.id, person])).get(current.responsiblePersonId)
  return {
    ...current,
    operatorPersonId: operator?.id ?? '',
    operatorName: operator?.name ?? '',
    responsiblePersonId: responsible?.id ?? '',
    responsibleName: responsible?.name ?? '',
    responsiblePersonTouched: responsible ? current.responsiblePersonTouched : false,
  }
}
