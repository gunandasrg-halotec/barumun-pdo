export interface PettyCashVoucherLine {
  id: string
  petty_cash_voucher_id: string
  pdo_detail_id: string
  pdo_detail?: {
    id: string
    description: string
    amount: number
    expense_item?: {
      id: string
      code: string
      name: string
      subcategory?: { name: string; category?: { name: string } }
    }
  }
  vehicle_id: string | null
  vehicle?: { id: string; nomor_polisi: string; nama: string } | null
  line_no: number
  description: string
  amount: number
  realization_entry_id: string | null
  realization_entry?: { id: string; proof_number: string } | null
}

export type PettyCashVoucherStatus = 'draft' | 'posted'

export interface PettyCashVoucher {
  id: string
  pdo_header_id: string
  voucher_number: string
  paid_to: string
  voucher_date: string
  status: PettyCashVoucherStatus
  total_amount: number
  created_by: string
  creator?: { id: string; full_name: string } | null
  scan_file_name: string | null
  scan_uploaded_at: string | null
  scan_uploaded_by: string | null
  scan_uploader?: { id: string; full_name: string } | null
  posted_at: string | null
  posted_by: string | null
  poster?: { id: string; full_name: string } | null
  lines: PettyCashVoucherLine[]
  created_at: string
  updated_at: string
}

export interface PettyCashVoucherLineInput {
  pdo_detail_id: string
  vehicle_id?: string | null
  description: string
  amount: number
}

export interface PettyCashVoucherFormInput {
  paid_to: string
  voucher_date: string
  lines: PettyCashVoucherLineInput[]
}

export interface PdoHasDraftVouchersError {
  code: 'PDO_HAS_DRAFT_VOUCHERS'
  message: string
  vouchers: Array<{ voucher_number: string; paid_to: string; total: number }>
}
