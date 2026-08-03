type SortableMeta = {
  id?: string
  code?: string
  name?: string
  display_order?: number
} | null

type GroupableDetail = {
  category: SortableMeta
  subcategory: SortableMeta
}

export type TransferSubgroup<T> = {
  subKey: string
  subLabel: string
  subOrder: number
  items: T[]
}

export type TransferCategoryGroup<T> = {
  catKey: string
  catLabel: string
  catOrder: number
  subs: TransferSubgroup<T>[]
}

const FALLBACK_ORDER = Number.MAX_SAFE_INTEGER

function cmpGroup(aOrder: number, aCode: string, bOrder: number, bCode: string) {
  if (aOrder !== bOrder) return aOrder - bOrder
  return aCode.localeCompare(bCode)
}

export function buildTransferDetailGroups<T extends GroupableDetail>(details: T[]): TransferCategoryGroup<T>[] {
  const catMap = new Map<string, { meta: SortableMeta; subs: Map<string, { meta: SortableMeta; items: T[] }> }>()

  for (const detail of details) {
    const cat = detail.category
    const sub = detail.subcategory

    const catKey = cat?.id ?? '__uncategorized__'
    const subKey = sub?.id ?? '__uncategorized__'

    if (!catMap.has(catKey)) catMap.set(catKey, { meta: cat, subs: new Map() })
    const catEntry = catMap.get(catKey)!

    if (!catEntry.subs.has(subKey)) catEntry.subs.set(subKey, { meta: sub, items: [] })
    catEntry.subs.get(subKey)!.items.push(detail)
  }

  return [...catMap.entries()]
    .map(([catKey, catEntry]) => ({
      catKey,
      catLabel: catEntry.meta ? `${catEntry.meta.code} — ${catEntry.meta.name}` : 'Uncategorized',
      catOrder: catEntry.meta?.display_order ?? FALLBACK_ORDER,
      subs: [...catEntry.subs.entries()]
        .map(([subKey, subEntry]) => ({
          subKey,
          subLabel: subEntry.meta ? `${subEntry.meta.code} — ${subEntry.meta.name}` : 'Uncategorized',
          subOrder: subEntry.meta?.display_order ?? FALLBACK_ORDER,
          items: subEntry.items,
        }))
        .sort((a, b) => cmpGroup(a.subOrder, a.subLabel, b.subOrder, b.subLabel)),
    }))
    .sort((a, b) => cmpGroup(a.catOrder, a.catLabel, b.catOrder, b.catLabel))
}
