export function toLocalDateTimeInput(date = new Date()) {
  const offset = date.getTimezoneOffset() * 60_000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

export function formatRupiah(value) {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(value) || 0)
}

export function formatIncidentDate(value, timezone, options = {}) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: timezone,
    dateStyle: options.dateOnly ? 'medium' : 'medium',
    ...(options.dateOnly ? {} : { timeStyle: 'short' }),
  }).format(new Date(value))
}

export function parseLoss(value) {
  if (value === '') return 0
  return Number(value)
}

export function mapIncidentError(error) {
  if (error?.code === '23505') return 'Log ini sudah tersimpan. Muat ulang riwayat untuk melihat hasilnya.'
  if (error?.code === '22007') return 'Tanggal kejadian tidak valid atau berada di masa depan.'
  if (error?.code === '22003' || error?.code === '23514') return 'Periksa kembali jumlah rusak dan nilai kerugian.'
  if (error?.code === '42501') return 'Peran Anda tidak diizinkan melakukan tindakan ini.'
  return error?.message || 'Log error tidak dapat disimpan. Coba lagi.'
}

