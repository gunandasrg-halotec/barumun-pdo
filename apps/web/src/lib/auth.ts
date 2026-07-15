import type { RoleCode } from '@/types'

// Role hierarchy helpers — digunakan untuk gate UI
export const ROLES_APPROVER: RoleCode[] = [
  'ASISTEN_KEBUN', 'MANAJER_KEBUN', 'MANAJER_KEUANGAN', 'DIREKTUR_KEUANGAN',
]

export const ROLES_FINANCE: RoleCode[] = ['MANAJER_KEUANGAN', 'STAFF_KEUANGAN', 'DIREKTUR_KEUANGAN']
export const ROLES_CROSS_UNIT: RoleCode[] = [
  'ADMIN', 'MANAJER_KEBUN', 'MANAJER_KEUANGAN', 'STAFF_KEUANGAN',
  'DIREKTUR_KEUANGAN', 'STAFF_PURCHASING',
]

export const canApprove       = (role: RoleCode) => ROLES_APPROVER.includes(role)
export const canTransfer      = (role: RoleCode) => ROLES_FINANCE.includes(role)
export const canRecordReal    = (role: RoleCode) => role === 'KERANI' || role === 'STAFF_PURCHASING'
export const isCrossUnit      = (role: RoleCode) => ROLES_CROSS_UNIT.includes(role)
export const isAdmin          = (role: RoleCode) => role === 'ADMIN'
export const isKerani         = (role: RoleCode) => role === 'KERANI'
export const isAsisteKebun    = (role: RoleCode) => role === 'ASISTEN_KEBUN'
export const canDeleteDraftPdo = (role: RoleCode) => role === 'KERANI' || role === 'ASISTEN_KEBUN'
export const isMgrKeu         = (role: RoleCode) => role === 'MANAJER_KEUANGAN'
export const isDirekturKeuangan = (role: RoleCode) => role === 'DIREKTUR_KEUANGAN'
export const isPurchasing     = (role: RoleCode) => role === 'STAFF_PURCHASING'
export const canEditMasterData = (role: RoleCode) => role === 'ADMIN' || role === 'STAFF_KEUANGAN'

// Label role untuk tampilan
export const ROLE_LABELS: Record<RoleCode, string> = {
  ADMIN:               'Admin',
  KERANI:              'Kerani',
  ASISTEN_KEBUN:       'Asisten Kebun',
  MANAJER_KEBUN:       'Manajer Kebun',
  MANAJER_KEUANGAN:    'Manajer Keuangan',
  STAFF_KEUANGAN:      'Staff Keuangan',
  DIREKTUR_KEUANGAN:   'Direktur Keuangan',
  STAFF_PURCHASING:    'Staff Purchasing',
}
