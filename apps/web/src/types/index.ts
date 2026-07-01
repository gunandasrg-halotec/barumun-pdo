// ─── Auth & User ────────────────────────────────────────────────────────────

export type RoleCode =
  | 'ADMIN'
  | 'KERANI'
  | 'ASISTEN_KEBUN'
  | 'MANAJER_KEBUN'
  | 'MANAJER_KEUANGAN'
  | 'STAFF_KEUANGAN'
  | 'DIREKTUR_KEUANGAN'
  | 'STAFF_PURCHASING'

export interface Role {
  id: string
  code: RoleCode
  name: string
}

export interface PlantationUnit {
  id: string
  code: string
  name: string
  is_active: boolean
  payroll_estate_external_id?: string | null
}

export interface AuthUser {
  id: string
  full_name: string
  email: string
  whatsapp_number: string
  is_active: boolean
  role: Role
  plantation_unit: PlantationUnit | null
  company_id: string
}

// ─── Master Data ─────────────────────────────────────────────────────────────

export interface ExpenseCategory {
  id: string
  code: string
  name: string
  display_order: number
  include_in_recap: boolean
  is_active: boolean
  notes: string | null
}

export interface ExpenseSubcategory {
  id: string
  category_id: string
  category?: ExpenseCategory
  code: string
  name: string
  display_order: number
  is_active: boolean
  notes: string | null
}

export type ModeInput = 'manual' | 'auto_external'
export type PayrollRole = 'pemanen' | 'bhl' | 'supir' | 'pegawai'

export interface ExpenseItem {
  id: string
  subcategory_id: string
  subcategory?: ExpenseSubcategory
  code: string
  name: string
  default_account_number: string | null
  default_unit: string | null
  default_rate: number | null
  mode_input: ModeInput
  external_source_system?: string | null
  external_component?: string | null
  external_component_key?: string | null
  external_role?: PayrollRole | null
  is_routine: boolean
  is_active: boolean
  is_deduction: boolean
  notes: string | null
}

// ─── PDO Header ──────────────────────────────────────────────────────────────

export type PdoStatus =
  | 'draft'
  | 'submitted'
  | 'reviewed_asisten'
  | 'in_review_manager'
  | 'in_review_direktur'
  | 'final'
  | 'closed'

export interface PdoHeader {
  id: string
  company_id: string
  plantation_unit_id: string
  plantation_unit?: PlantationUnit
  created_by: string
  creator?: AuthUser
  pdo_number: string
  period_month: number
  period_year: number
  submission_date: string | null
  status: PdoStatus
  closure_type: 'system' | 'manual' | null
  closed_at: string | null
  closure_notes: string | null
  closer: { full_name: string } | null
  notes: string | null
  grand_total_amount: number
  // BR-APPR-002: parallel manager approval tracking
  manager_kebun_approved: boolean | null
  manager_keuangan_approved: boolean | null
  created_at: string
  updated_at: string
  // computed
  total_amount?: number
  total_transferred?: number
  total_realized?: number
  balance?: number
}

// ─── PDO Detail ──────────────────────────────────────────────────────────────

export interface PdoDetail {
  id: string
  pdo_header_id: string
  expense_item_id: string
  expense_item?: ExpenseItem
  source_pdo_supplementary_id: string | null
  account_number: string | null
  description: string
  quantity: number | null
  unit: string | null
  rate: number | null
  amount: number
  external_source_system?: string | null
  external_component?: string | null
  external_component_key?: string | null
  is_auto_external_active?: boolean
  needs_pull?: boolean
  is_stale_external_snapshot?: boolean
  is_external_read_only?: boolean
  external_amount_pulled_at?: string | null
  external_payload?: {
    status?: string
    amount?: number
    unit?: string | null
    volume?: number
    component?: string
    component_label?: string
    component_key?: string | null
    period?: string
    estate_external_id?: string
    generated_at?: string | null
    role?: PayrollRole | null
    role_label?: string | null
    source_system?: string | null
  } | null
  notes: string | null
  display_order: number
  // computed
  total_transferred?: number
  total_realized?: number
}

// ─── Approval ────────────────────────────────────────────────────────────────

export type ApprovalAction = 'submit' | 'approve' | 'reject' | 'resubmit' | 'close'

export interface PdoApprovalLog {
  id: string
  pdo_header_id: string
  actor_user_id: string
  actor?: AuthUser
  approval_stage: string
  action: ApprovalAction
  reason: string | null
  sequence_number: number
  created_at: string
}

// ─── Transfer ────────────────────────────────────────────────────────────────

export type TransferSource = 'system' | 'manual'

export interface TransferEntry {
  id: string
  pdo_detail_id: string
  recorded_by: string | null
  recorder?: AuthUser | null
  entry_source: TransferSource
  is_auto_generated: boolean
  transfer_date: string
  amount: number
  reference_number: string
  notes: string | null
  created_at: string
}

// ─── Realization ─────────────────────────────────────────────────────────────

export type PaymentMethod = 'tunai' | 'transfer' | 'kas_kecil'
export type FundingSource = 'kas_kebun' | 'rekening_kebun' | 'rekening_utama'

export interface RealizationEntry {
  id: string
  pdo_detail_id: string
  recorded_by: string
  recorder?: AuthUser
  transaction_date: string
  amount: number
  payment_method: PaymentMethod
  proof_number: string
  funding_source: FundingSource
  explanation: string | null
  created_at: string
  attachments?: RealizationAttachment[]
}

export interface RealizationAttachment {
  id: string
  realization_entry_id: string
  uploaded_by: string
  file_name: string
  file_path: string
  mime_type: string
  file_size_bytes: number
  created_at: string
  temporary_url?: string
}

// ─── PDO Supplementary ───────────────────────────────────────────────────────

export type SupplementaryStatus =
  | 'draft'
  | 'submitted'
  | 'reviewed_asisten'
  | 'in_review_manager'
  | 'in_review_direktur'
  | 'final_merged'
  | 'rejected'

export interface PdoSupplementaryHeader {
  id: string
  parent_pdo_header_id: string
  company_id: string
  plantation_unit_id: string
  created_by: string
  pdo_number: string
  period_month: number
  period_year: number
  submission_date: string | null
  status: SupplementaryStatus
  merged_at: string | null
  notes: string | null
}

// ─── Dashboard ───────────────────────────────────────────────────────────────

export interface DashboardUnitSummary {
  unit_id:               string
  unit_code:             string
  unit_name:             string
  total_amount:          number
  total_transferred:     number
  total_realized:        number
  transferred_rek_kebun: number
  transferred_pribadi:   number
  transferred_vendor:    number
}

export interface DashboardSummary {
  total_amount:       number
  total_transferred:  number
  total_realized:     number
  balance:            number
  items_without_proof:number
  pending_pdo_count:  number
  transferred_by_destination: {
    rek_kebun: number
    pribadi:   number
    vendor:    number
  }
  by_unit: DashboardUnitSummary[]
}

export interface CategorySummary {
  category_id: string
  category_name: string
  total_amount: number
  total_realized: number
  percentage: number
}

// ─── API Response ────────────────────────────────────────────────────────────

export interface ApiResponse<T> {
  success: boolean
  data: T
  message?: string
}

export interface PaginatedResponse<T> {
  success: boolean
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
}

export interface ApiError {
  success: false
  error: {
    code: string
    message: string
    details?: Array<{
      field: string
      message: string
    }>
    errors?: Record<string, string[]>
  }
}

// ─── System Settings ─────────────────────────────────────────────────────────

export interface SystemSetting {
  key: string
  value: string
  description: string | null
}

export interface NotificationTemplate {
  id: string
  event_type: string
  channel: string
  template_body: string
  is_active: boolean
}

// ─── Reports ─────────────────────────────────────────────────────────────────

export interface ReportFilter {
  period_month?: number
  period_year?: number
  plantation_unit_id?: string
  category_id?: string
}
