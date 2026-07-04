import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { RecapResponse } from '@/types/recap'
import type { ApiResponse } from '@/types'

export interface RecapFilters {
  period_year: number
  period_month: number
  unit_id?: string
  category_id?: string
  start_date?: string
  end_date?: string
}

export function useRecapData(filters: RecapFilters, enabled = true) {
  return useQuery<RecapResponse>({
    queryKey: ['recap', filters],
    queryFn: async () => {
      const params: Record<string, string | number> = {
        period_year:  filters.period_year,
        period_month: filters.period_month,
      }
      if (filters.unit_id)     params.unit_id     = filters.unit_id
      if (filters.category_id) params.category_id = filters.category_id
      if (filters.start_date)  params.start_date  = filters.start_date
      if (filters.end_date)    params.end_date    = filters.end_date

      const res = await api.get<ApiResponse<RecapResponse>>('/reports/recap', { params })
      return res.data.data
    },
    enabled,
    staleTime: 60_000,
  })
}
