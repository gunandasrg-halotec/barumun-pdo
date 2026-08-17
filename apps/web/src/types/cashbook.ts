export interface CashBookRow {
  date: string
  type: 'penerimaan' | 'pengeluaran'
  reference: string | null
  description: string
  notes: string | null
  /** Voucher Petty Cash yang menghasilkan baris ini (§3g), null kalau bukan dari voucher. */
  vouchers: { id: string; voucher_number: string }[] | null
  amount: number
  balance: number
}

export interface CashBookResponse {
  opening_balance: number
  closing_balance: number
  total_penerimaan: number
  total_pengeluaran: number
  rows: CashBookRow[]
  period_label: string
  unit: { code: string; name: string } | null
}
