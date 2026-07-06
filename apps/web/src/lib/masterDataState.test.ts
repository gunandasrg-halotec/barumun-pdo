import { describe, expect, it } from 'vitest'
import {
  buildMasterDataSearchParams,
  buildMasterDataUrl,
  parseMasterDataViewState,
  resolveMasterDataReturnTo,
} from './masterDataState'

describe('masterDataState', () => {
  it('parses tab and expanded branches from query string', () => {
    const state = parseMasterDataViewState(
      new URLSearchParams('tab=hierarki&cats=cat-2,cat-1,cat-1&subs=sub-9'),
      ['hierarki', 'items', 'users'],
    )

    expect(state).toEqual({
      tab: 'hierarki',
      expandedCatIds: ['cat-2', 'cat-1'],
      expandedSubIds: ['sub-9'],
    })
  })

  it('falls back to first allowed tab when query tab invalid', () => {
    const state = parseMasterDataViewState(
      new URLSearchParams('tab=kebun'),
      ['hierarki', 'items', 'users'],
    )

    expect(state.tab).toBe('hierarki')
  })

  it('serializes view state into stable master url', () => {
    const params = buildMasterDataSearchParams({
      tab: 'items',
      expandedCatIds: ['cat-1'],
      expandedSubIds: ['sub-1', 'sub-2'],
    })

    expect(params.toString()).toBe('tab=items&cats=cat-1&subs=sub-1%2Csub-2')
    expect(buildMasterDataUrl({
      tab: 'items',
      expandedCatIds: ['cat-1'],
      expandedSubIds: ['sub-1', 'sub-2'],
    })).toBe('/master?tab=items&cats=cat-1&subs=sub-1%2Csub-2')
  })

  it('rejects non-master return target', () => {
    expect(resolveMasterDataReturnTo(null)).toBe('/master')
    expect(resolveMasterDataReturnTo(encodeURIComponent('/pdo'))).toBe('/master')
    expect(resolveMasterDataReturnTo(encodeURIComponent('/master?tab=users'))).toBe('/master?tab=users')
  })
})

