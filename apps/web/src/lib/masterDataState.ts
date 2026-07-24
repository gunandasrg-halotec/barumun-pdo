export type MasterDataTab = 'hierarki' | 'items' | 'kebun' | 'kendaraan' | 'users'

type MasterDataViewState = {
  tab: MasterDataTab
  expandedCatIds: string[]
  expandedSubIds: string[]
}

const MASTER_DATA_PATH = '/master'

function normalizeIds(value: string | null): string[] {
  if (!value) return []

  return Array.from(new Set(
    value
      .split(',')
      .map((id) => id.trim())
      .filter(Boolean),
  ))
}

export function parseMasterDataViewState(
  searchParams: URLSearchParams,
  allowedTabs: readonly MasterDataTab[],
): MasterDataViewState {
  const requestedTab = searchParams.get('tab')
  const fallbackTab = allowedTabs[0] ?? 'hierarki'
  const tab = allowedTabs.includes(requestedTab as MasterDataTab)
    ? requestedTab as MasterDataTab
    : fallbackTab

  return {
    tab,
    expandedCatIds: normalizeIds(searchParams.get('cats')),
    expandedSubIds: normalizeIds(searchParams.get('subs')),
  }
}

export function buildMasterDataSearchParams(state: MasterDataViewState): URLSearchParams {
  const searchParams = new URLSearchParams()

  searchParams.set('tab', state.tab)

  if (state.expandedCatIds.length > 0) {
    searchParams.set('cats', state.expandedCatIds.join(','))
  }

  if (state.expandedSubIds.length > 0) {
    searchParams.set('subs', state.expandedSubIds.join(','))
  }

  return searchParams
}

export function buildMasterDataUrl(state: MasterDataViewState): string {
  const query = buildMasterDataSearchParams(state).toString()
  return query ? `${MASTER_DATA_PATH}?${query}` : MASTER_DATA_PATH
}

export function buildMasterDataReturnTo(path: string): string {
  return encodeURIComponent(path)
}

export function resolveMasterDataReturnTo(value: string | null | undefined): string {
  if (!value) return MASTER_DATA_PATH

  try {
    const decoded = decodeURIComponent(value)
    return decoded.startsWith(MASTER_DATA_PATH) ? decoded : MASTER_DATA_PATH
  } catch {
    return MASTER_DATA_PATH
  }
}
