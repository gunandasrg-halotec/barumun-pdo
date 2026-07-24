import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { CashBookResponse } from '@/types/cashbook'
import type { ApiResponse } from '@/types'

export interface CashBookFilters {
  period_year: number
  period_month: number
  unit_id?: string
  start_date?: string
  end_date?: string
}

export function useCashBookData(filters: CashBookFilters, enabled = true) {
  return useQuery<CashBookResponse>({
    queryKey: ['cashbook', filters],
    queryFn: async () => {
      const params: Record<string, string | number> = {
        period_year:  filters.period_year,
        period_month: filters.period_month,
      }
      if (filters.unit_id)    params.unit_id    = filters.unit_id
      if (filters.start_date) params.start_date = filters.start_date
      if (filters.end_date)   params.end_date   = filters.end_date

      const res = await api.get<ApiResponse<CashBookResponse>>('/reports/cashbook', { params })
      return res.data.data
    },
    enabled,
    staleTime: 60_000,
  })
}
