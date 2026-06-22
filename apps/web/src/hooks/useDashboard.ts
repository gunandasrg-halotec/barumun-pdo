import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { DashboardSummary, CategorySummary, ApiResponse } from '@/types'

export function useDashboard(params?: Record<string, string | number | string[] | undefined>) {
  return useQuery({
    queryKey: ['dashboard', params],
    queryFn: async () => {
      const res = await api.get<ApiResponse<DashboardSummary>>('/dashboard', { params })
      return res.data.data
    },
    refetchInterval: 60_000, // refresh tiap 1 menit (sesuai NFR)
  })
}

export function useCategorySummary(params?: Record<string, string | number | string[] | undefined>) {
  return useQuery({
    queryKey: ['dashboard', 'category', params],
    queryFn: async () => {
      const res = await api.get<ApiResponse<CategorySummary[]>>('/dashboard/category-summary', { params })
      return res.data.data
    },
  })
}
