import { describe, expect, it } from 'vitest'
import {
  ALL_TRANSFER_DEST_OPTIONS,
  DEDUCTION_TRANSFER_DEST_OPTIONS,
  getTransferDestOptions,
  normalizeTransferDest,
} from './transferDestinations'

describe('transferDestinations', () => {
  it('keeps all destinations for non-deduction items', () => {
    expect(getTransferDestOptions(false)).toEqual(ALL_TRANSFER_DEST_OPTIONS)
  })

  it('limits deduction items to rekening kebun and pribadi', () => {
    expect(getTransferDestOptions(true)).toEqual(DEDUCTION_TRANSFER_DEST_OPTIONS)
  })

  it('normalizes legacy vendor destination for deduction items', () => {
    expect(normalizeTransferDest('vendor', true)).toBe('rek_kebun')
    expect(normalizeTransferDest('pribadi', true)).toBe('pribadi')
    expect(normalizeTransferDest('vendor', false)).toBe('vendor')
  })
})
