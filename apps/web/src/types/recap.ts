export interface RecapItem {
  no: number
  pdo_detail_id: string
  item_code: string
  item_name: string
  account_number: string
  description: string
  notes: string | null
  amount: number
  total_transfer: number
  total_realization: number
  saldo: number
  is_deduction: boolean
  is_overbudget: boolean
}

export interface RecapSubcategory {
  subcategory_code: string
  subcategory_name: string
  subtotal_amount: number
  subtotal_transfer: number
  subtotal_realization: number
  subtotal_saldo: number
  items: RecapItem[]
}

export interface RecapCategory {
  no: number
  category_code: string
  category_name: string
  subtotal_amount: number
  subtotal_transfer: number
  subtotal_realization: number
  subtotal_saldo: number
  subcategories: RecapSubcategory[]
}

export interface RecapResponse {
  period_label: string
  unit: { code: string; name: string } | null
  grand_total_amount: number
  grand_total_transfer: number
  grand_total_realization: number
  grand_total_saldo: number
  transfer_kebun: number
  transfer_pribadi: number
  realisasi_kebun: number
  realisasi_pribadi: number
  /** saldo awal + transfer − realisasi (posisi kas kebun sebenarnya) */
  saldo_kebun: number
  /** transfer − realisasi, tanpa saldo awal — dana dari PDO periode ini saja */
  saldo_pdo_kebun: number
  saldo_pribadi: number
  saldo_awal: number
  /** alias historis dari saldo_kebun */
  saldo_kas_kebun_saat_ini: number
  categories: RecapCategory[]
}

export type ExportJobStatus = 'queued' | 'processing' | 'done' | 'failed'
