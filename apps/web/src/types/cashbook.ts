export interface CashBookRow {
  date: string
  type: 'penerimaan' | 'pengeluaran'
  reference: string | null
  description: string
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
