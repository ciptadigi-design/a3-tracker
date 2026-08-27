export const replacementReasons = [
  { value: 'normal_eol', label: 'Umur normal tercapai', learning: true },
  { value: 'depleted', label: 'Habis / depleted', learning: true },
  { value: 'print_quality', label: 'Kualitas cetak menurun', learning: true },
  { value: 'preventive', label: 'Penggantian preventif', learning: false },
  { value: 'failure', label: 'Gagal / rusak', learning: false },
  { value: 'damage', label: 'Kerusakan fisik', learning: false },
  { value: 'contamination', label: 'Kontaminasi / kotor', learning: false },
  { value: 'diagnostic', label: 'Hasil diagnosis teknisi', learning: false },
  { value: 'other', label: 'Lainnya', learning: false },
]

export const removalConditions = [
  { value: 'good', label: 'Baik' },
  { value: 'fair', label: 'Menurun' },
  { value: 'worn', label: 'Aus' },
  { value: 'failed', label: 'Rusak' },
]

export const replacementReasonLabels = Object.fromEntries(replacementReasons.map((item) => [item.value, item.label]))
export const removalConditionLabels = Object.fromEntries(removalConditions.map((item) => [item.value, item.label]))

export function learningDefault(reason) {
  return replacementReasons.find((item) => item.value === reason)?.learning ?? false
}
