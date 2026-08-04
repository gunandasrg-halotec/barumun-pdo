import { describe, expect, it } from 'vitest'
import { buildTransferDetailGroups } from './transferDetailGroups'

type Detail = {
  id: string
  category: { id: string; code: string; name: string; display_order: number } | null
  subcategory: { id: string; code: string; name: string; display_order: number } | null
}

describe('buildTransferDetailGroups', () => {
  it('groups by category then subcategory using master display order while keeping item order', () => {
    const details: Detail[] = [
      {
        id: 'item-1',
        category: { id: 'cat-b', code: 'B', name: 'Beta', display_order: 2 },
        subcategory: { id: 'sub-b2', code: 'B2', name: 'Beta Dua', display_order: 2 },
      },
      {
        id: 'item-2',
        category: { id: 'cat-a', code: 'A', name: 'Alpha', display_order: 1 },
        subcategory: { id: 'sub-a1', code: 'A1', name: 'Alpha Satu', display_order: 1 },
      },
      {
        id: 'item-3',
        category: { id: 'cat-b', code: 'B', name: 'Beta', display_order: 2 },
        subcategory: { id: 'sub-b2', code: 'B2', name: 'Beta Dua', display_order: 2 },
      },
      {
        id: 'item-4',
        category: { id: 'cat-b', code: 'B', name: 'Beta', display_order: 2 },
        subcategory: { id: 'sub-b1', code: 'B1', name: 'Beta Satu', display_order: 1 },
      },
    ]

    const groups = buildTransferDetailGroups(details)

    expect(groups.map((g) => g.catLabel)).toEqual(['A — Alpha', 'B — Beta'])
    expect(groups[1].subs.map((g) => g.subLabel)).toEqual(['B1 — Beta Satu', 'B2 — Beta Dua'])
    expect(groups[1].subs[1].items.map((item) => item.id)).toEqual(['item-1', 'item-3'])
  })

  it('pushes uncategorized fallback groups to the end', () => {
    const details: Detail[] = [
      {
        id: 'item-known',
        category: { id: 'cat-a', code: 'A', name: 'Alpha', display_order: 1 },
        subcategory: { id: 'sub-a1', code: 'A1', name: 'Alpha Satu', display_order: 1 },
      },
      { id: 'item-uncat', category: null, subcategory: null },
    ]

    const groups = buildTransferDetailGroups(details)

    expect(groups.map((g) => g.catLabel)).toEqual(['A — Alpha', 'Uncategorized'])
    expect(groups[1].subs.map((g) => g.subLabel)).toEqual(['Uncategorized'])
  })
})
