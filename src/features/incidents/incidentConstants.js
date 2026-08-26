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
  resolved: 'Diselesaikan',
  voided: 'Voided',
}

export const incidentRevisionFieldLabels = {
  occurred_at: 'Tanggal Kejadian',
  invoice_number: 'No. Invoice CRM',
  customer_name_snapshot: 'Nama Konsumen',
  product_name_snapshot: 'Nama Produk',
  category: 'Kategori',
  incident_type: 'Jenis',
  machine_id: 'Machine',
  qty_affected: 'Qty Rusak',
  responsible_user_id: 'PIC akun',
  responsible_name_snapshot: 'PIC Terlibat',
  material_loss: 'Rugi Bahan',
  service_loss: 'Rugi Jasa',
  description: 'Deskripsi Kesalahan',
  cause: 'Penyebab Kesalahan',
  prevention: 'Solusi & Pencegahan',
  customer_resolution: 'Penyelesaian Untuk Konsumen',
}
