export type TransferDestTotals = { rek_kebun: number; pribadi: number; vendor: number }

export type TransferSummaryDetail = {
  amount_approved: number
  funding_option:  string | null
  expense_item:    { is_deduction?: boolean } | null
  final_by_dest:   TransferDestTotals
  draft_by_dest:   TransferDestTotals
}

export type TransferSummaryCards = {
  /** Pengajuan SIGNED — item potongan mengurangi. Sama dengan grand_total_amount di Daftar PDO. */
  totalPengajuan: number
  /** Nominal seluruh item potongan (positif); UI menampilkannya sebagai minus. */
  totalPotongan: number
  /** Transfer tercatat (committed + draft) per tujuan. */
  dest: TransferDestTotals
  /** Pengajuan yang masih perlu ditransfer. Lihat catatan di bawah. */
  sisa: number
}

const sumDest = (d: TransferDestTotals): number => d.rek_kebun + d.pribadi + d.vendor

/**
 * Ringkasan kartu halaman Detail Rencana Transfer.
 *
 * "Sisa Dana" SENGAJA dihitung di atas dasar BRUTO di kedua sisi:
 *
 *     sisa = Σ pengajuan item NON-potongan − Σ transfer item NON-potongan
 *
 * bukan `totalPengajuan (signed) − seluruh transfer`. Sebabnya, potongan panjar masuk
 * hitungan DUA KALI pada waktu yang berbeda:
 *
 *   1. sebagai item minus di pengajuan — ada sejak PDO dibuat; dan
 *   2. sebagai TransferEntry NEGATIF ke rek. kebun — baru dibuat sistem saat
 *      "Simpan Permanen" (TransferEntryService::applyDeductionEntries()).
 *
 * Rumus lama memakai pengajuan signed dikurangi SELURUH transfer, sehingga sebelum
 * Simpan Permanen potongan sudah mengurangi pengajuan padahal entri negatifnya belum
 * ada — Sisa Dana terlalu kecil sebesar nilai potongan (PDO-2026-09-JM-001 tampil
 * −1.040.000, seharusnya +1.560.000) — dan sesudah Simpan Permanen malah terhitung dua
 * kali. Entri potongan selalu menempel pada pdo_detail item potongan, jadi dengan basis
 * bruto ia otomatis tidak ikut di kedua sisi dan angkanya stabil di kedua keadaan.
 *
 * Item PDOT "Gunakan Kas Kebun" dikeluarkan dari basis: dananya sudah ada di kas kebun
 * dan tidak pernah perlu ditransfer. Ia TETAP dihitung di Total Pengajuan karena memang
 * bagian dari pengajuan PDO ini.
 */
export function buildTransferSummaryCards(details: TransferSummaryDetail[]): TransferSummaryCards {
  const dest: TransferDestTotals = { rek_kebun: 0, pribadi: 0, vendor: 0 }

  let totalPengajuan = 0
  let totalPotongan  = 0
  let basisPengajuan = 0
  let transferBruto  = 0

  for (const d of details) {
    const isDeduction = !!d.expense_item?.is_deduction

    totalPengajuan += isDeduction ? -d.amount_approved : d.amount_approved
    if (isDeduction) totalPotongan += d.amount_approved

    // Transfer tercatat: committed (final) + draft tersimpan. Nilai input di form yang
    // belum disimpan TIDAK ikut — itu baru usulan, kalau dijumlahkan Sisa Dana selalu 0.
    dest.rek_kebun += d.final_by_dest.rek_kebun + d.draft_by_dest.rek_kebun
    dest.pribadi   += d.final_by_dest.pribadi   + d.draft_by_dest.pribadi
    dest.vendor    += d.final_by_dest.vendor    + d.draft_by_dest.vendor

    if (isDeduction) continue

    if (d.funding_option !== 'kas_kebun') basisPengajuan += d.amount_approved
    transferBruto += sumDest(d.final_by_dest) + sumDest(d.draft_by_dest)
  }

  return { totalPengajuan, totalPotongan, dest, sisa: basisPengajuan - transferBruto }
}
