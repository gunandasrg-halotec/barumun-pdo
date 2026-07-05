export interface RecapItem {
  no: number
  pdo_detail_id: string
  item_code: string
  item_name: string
  account_number: string
  description: string
  amount: number
  total_transfer: number
  total_realization: number
  saldo: number
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
  saldo_kebun: number
  saldo_pribadi: number
  categories: RecapCategory[]
}

export type ExportJobStatus = 'queued' | 'processing' | 'done' | 'failed'
