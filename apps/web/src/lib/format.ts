// Format angka ke format Rupiah Indonesia
export const fmt = (n: number | null | undefined): string =>
  'Rp ' + Number(n ?? 0).toLocaleString('id-ID')

// Format singkat (jt = juta)
export const fmtShort = (n: number | null | undefined): string => {
  const num = Number(n ?? 0)
  if (num >= 1_000_000_000) return 'Rp ' + (num / 1_000_000_000).toFixed(1) + ' M'
  if (num >= 1_000_000)     return 'Rp ' + (num / 1_000_000).toFixed(1) + ' jt'
  if (num >= 1_000)         return 'Rp ' + (num / 1_000).toFixed(0) + ' rb'
  return fmt(num)
}

// Format persen
export const fmtPct = (realized: number, total: number): string => {
  if (!total) return '0%'
  return Math.min(Math.round((realized / total) * 100), 100) + '%'
}

// Format tanggal Indonesia
export const fmtDate = (dateStr: string | null | undefined): string => {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

// Format periode bulan/tahun
const BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
               'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']

export const fmtPeriode = (month: number, year: number): string =>
  `${BULAN[month]} ${year}`

export const fmtPeriodeShort = (month: number, year: number): string =>
  `${String(month).padStart(2, '0')}/${year}`

// Inisial nama untuk avatar
export const getInitials = (name: string): string =>
  name.trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase()

// Hari terakhir bulan
export const lastDayOfMonth = (year: number, month: number): Date =>
  new Date(year, month, 0)
