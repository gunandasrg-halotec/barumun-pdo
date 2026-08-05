export type TransferDest = 'rek_kebun' | 'pribadi' | 'vendor'

export const TRANSFER_DEST_LABELS: Record<TransferDest, string> = {
  rek_kebun: 'Rek. Kebun',
  pribadi:   'Pribadi',
  vendor:    'Vendor',
}

export const ALL_TRANSFER_DEST_OPTIONS: TransferDest[] = ['rek_kebun', 'pribadi', 'vendor']
export const DEDUCTION_TRANSFER_DEST_OPTIONS: TransferDest[] = ['rek_kebun', 'pribadi']

export function getTransferDestOptions(isDeduction: boolean): TransferDest[] {
  return isDeduction ? DEDUCTION_TRANSFER_DEST_OPTIONS : ALL_TRANSFER_DEST_OPTIONS
}

export function normalizeTransferDest(dest: TransferDest, isDeduction: boolean): TransferDest {
  if (isDeduction && dest === 'vendor') return 'rek_kebun'
  return dest
}
