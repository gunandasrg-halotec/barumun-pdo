export type ReportType = 'realization' | 'over_budget' | 'missing_proof' | 'recap'
export type ExportFormat = 'xlsx' | 'pdf'
export type RealizationStatus =
  | 'sesuai'
  | 'over_budget'
  | 'belum_realisasi'
  | 'belum_bukti'
  | 'partial'

export interface RealizationRow {
  detail_id: string
  pdo_header_id: string
  pdo_number: string
  period_year: number
  period_month: number
  pdo_status: string
  unit_code: string
  unit_name: string
  category_id: string
  category_code: string
  category_name: string
  subcategory_code: string
  subcategory_name: string
  item_name: string
  item_code: string
  account_number: string
  description: string
  amount: number
  total_transfer: number
  total_realization: number
  saldo: number
  realization_pct: number
  status: RealizationStatus
}

export interface MissingProofRow {
  pdo_number: string
  unit_name: string
  item_name: string
  keterangan: string
  transaction_date: string
  amount: number
  recorded_by: string
}

export interface RecapItem {
  no: number
  item_code: string
  item_name: string
  account_number: string
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

export interface RecapData {
  grand_total_amount: number
  grand_total_transfer: number
  grand_total_realization: number
  grand_total_saldo: number
  categories: RecapCategory[]
}

export type ExportJobStatus = 'queued' | 'processing' | 'done' | 'failed'

export interface ExportJobState {
  status: ExportJobStatus
  report_type: ReportType
  format: ExportFormat
  created_at: string
  url?: string
  error?: string
}

export interface ReportFilters {
  period_year: number
  period_month: number
  unit_id?: string
  category_id?: string
}
