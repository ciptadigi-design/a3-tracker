export const incidentCategories = [
  { value: 'kesesuaian', label: 'Kesesuaian' },
  { value: 'kualitas', label: 'Kualitas' },
  { value: 'desain', label: 'Desain' },
  { value: 'bahan', label: 'Bahan' },
  { value: 'prosedur', label: 'Prosedur' },
]

export const incidentTypes = [
  { value: 'machine_operation', label: 'Mesin' },
  { value: 'human', label: 'Human' },
  { value: 'test_print', label: 'Tes Print' },
]

export const categoryLabels = Object.fromEntries(incidentCategories.map((item) => [item.value, item.label]))
export const incidentTypeLabels = Object.fromEntries(incidentTypes.map((item) => [item.value, item.label]))

export const incidentStatusLabels = {
  open: 'Open',
  resolved: 'Resolved',
  voided: 'Voided',
}

