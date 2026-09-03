import { describe, expect, it } from 'vitest'
import { buildTransferSummaryCards, type TransferSummaryDetail } from './transferSummary'

const zero = () => ({ rek_kebun: 0, pribadi: 0, vendor: 0 })

function detail(over: Partial<TransferSummaryDetail> = {}): TransferSummaryDetail {
  return {
    amount_approved: 0,
    funding_option:  null,
    expense_item:    { is_deduction: false },
    final_by_dest:   zero(),
    draft_by_dest:   zero(),
    ...over,
  }
}

describe('buildTransferSummaryCards', () => {
  /**
   * Data nyata PDO-2026-09-JM-001: seluruh transfer masih draft, entri potongan
   * negatif belum dibuat (baru muncul saat Simpan Permanen).
   */
  it('tidak menghitung potongan dua kali sebelum Simpan Permanen', () => {
    const cards = buildTransferSummaryCards([
      detail({
        amount_approved: 48_760_500,
        draft_by_dest:   { rek_kebun: 8_765_500, pribadi: 4_830_000, vendor: 33_605_000 },
      }),
      detail({ amount_approved: 2_600_000, expense_item: { is_deduction: true } }),
    ])

    expect(cards.totalPengajuan).toBe(46_160_500)
    expect(cards.totalPotongan).toBe(2_600_000)
    expect(cards.dest).toEqual({ rek_kebun: 8_765_500, pribadi: 4_830_000, vendor: 33_605_000 })
    // 48.760.500 − 47.200.500. Rumus lama menghasilkan −1.040.000.
    expect(cards.sisa).toBe(1_560_000)
  })

  /** Angka Sisa Dana harus SAMA sesudah Simpan Permanen, saat entri potongan sudah ada. */
  it('menghasilkan Sisa Dana yang sama sesudah Simpan Permanen', () => {
    const cards = buildTransferSummaryCards([
      detail({
        amount_approved: 48_760_500,
        final_by_dest:   { rek_kebun: 8_765_500, pribadi: 4_830_000, vendor: 33_605_000 },
      }),
      detail({
        amount_approved: 2_600_000,
        expense_item:    { is_deduction: true },
        // Entri negatif yang dibuat sistem saat Simpan Permanen.
        final_by_dest:   { rek_kebun: -2_600_000, pribadi: 0, vendor: 0 },
      }),
    ])

    expect(cards.sisa).toBe(1_560_000)
    // Kartu tujuan tetap menampilkan nilai BERSIH setelah potongan.
    expect(cards.dest.rek_kebun).toBe(6_165_500)
  })

  it('mengeluarkan item PDOT "Gunakan Kas Kebun" dari basis Sisa Dana', () => {
    const cards = buildTransferSummaryCards([
      detail({ amount_approved: 1_000_000, draft_by_dest: { rek_kebun: 400_000, pribadi: 0, vendor: 0 } }),
      detail({ amount_approved: 250_000, funding_option: 'kas_kebun' }),
    ])

    // Item kas kebun tetap masuk Total Pengajuan…
    expect(cards.totalPengajuan).toBe(1_250_000)
    // …tapi tidak menambah sisa yang perlu ditransfer: 1.000.000 − 400.000.
    expect(cards.sisa).toBe(600_000)
  })

  it('menghasilkan nol saat seluruh pengajuan sudah ditransfer', () => {
    const cards = buildTransferSummaryCards([
      detail({ amount_approved: 5_000_000, final_by_dest: { rek_kebun: 5_000_000, pribadi: 0, vendor: 0 } }),
    ])

    expect(cards.sisa).toBe(0)
  })
})
