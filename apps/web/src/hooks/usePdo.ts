import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { PdoHeader, PdoDetail, PdoApprovalLog, ApiResponse, PaginatedResponse, ExpenseCategory, ExpenseSubcategory } from '@/types'

// Shape returned by GET /pdo/:id (hierarchical response from [E])
export interface PdoGroupedResponse {
  pdo: PdoHeader
  categories: Array<{
    category: Pick<ExpenseCategory, 'id' | 'code' | 'name' | 'display_order'> | null
    subcategories: Array<{
      subcategory: Pick<ExpenseSubcategory, 'id' | 'code' | 'name' | 'display_order'> | null
      details: PdoDetail[]
      subtotal_amount: number
    }>
    subtotal_amount: number
  }>
  grand_total: number
}

// ─── List ────────────────────────────────────────────────────────────────────

export function usePdoList(params?: Record<string, string | number | undefined>) {
  return useQuery({
    queryKey: ['pdo', 'list', params],
    queryFn: async () => {
      const res = await api.get<PaginatedResponse<PdoHeader>>('/pdo', { params })
      return res.data
    },
  })
}

// ─── Detail ──────────────────────────────────────────────────────────────────

export function usePdo(id: string | undefined) {
  return useQuery({
    queryKey: ['pdo', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoGroupedResponse>>(`/pdo/${id}`)
      // [E] show() returns {pdo, categories, grand_total} — extract pdo object
      return res.data.data.pdo
    },
    enabled: !!id,
  })
}

export function usePdoGrouped(id: string | undefined) {
  return useQuery({
    queryKey: ['pdo', id, 'grouped'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoGroupedResponse>>(`/pdo/${id}`)
      return res.data.data
    },
    enabled: !!id,
  })
}

export function usePdoDetails(pdoId: string | undefined) {
  return useQuery({
    queryKey: ['pdo', pdoId, 'details'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoDetail[]>>(`/pdo/${pdoId}/details`)
      return res.data.data
    },
    enabled: !!pdoId,
  })
}

// ─── Approval history ────────────────────────────────────────────────────────

export function usePdoApprovalHistory(pdoId: string | undefined) {
  return useQuery({
    queryKey: ['pdo', pdoId, 'approval-history'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoApprovalLog[]>>(`/pdo/${pdoId}/approval-history`)
      return res.data.data
    },
    enabled: !!pdoId,
  })
}

// ─── Mutations ───────────────────────────────────────────────────────────────

export function useCreatePdo() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<PdoHeader>>('/pdo', data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pdo'] }),
  })
}

export function useUpdatePdo(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.put<ApiResponse<PdoHeader>>(`/pdo/${id}`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pdo'] }),
  })
}

export function useAddDetail(pdoId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<PdoDetail>>(`/pdo/${pdoId}/details`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pdo', pdoId] }),
  })
}

export function useUpdateDetail(pdoId: string, detailId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.put<ApiResponse<PdoDetail>>(`/pdo/${pdoId}/details/${detailId}`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pdo', pdoId] }),
  })
}

export function useDeleteDetail(pdoId: string, detailId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => api.delete(`/pdo/${pdoId}/details/${detailId}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pdo', pdoId] }),
  })
}

// ─── Approval actions ────────────────────────────────────────────────────────

export function useSubmitPdo(pdoId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: { submission_date: string }) => {
      const res = await api.post<ApiResponse<PdoHeader>>(`/pdo/${pdoId}/submit`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pdo'] }),
  })
}

export function useApprovePdo(pdoId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: { reason?: string }) => {
      const res = await api.post<ApiResponse<PdoHeader>>(`/pdo/${pdoId}/approve`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pdo'] }),
  })
}

export function useRejectPdo(pdoId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: { reason: string }) => {
      const res = await api.post<ApiResponse<PdoHeader>>(`/pdo/${pdoId}/reject`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pdo'] }),
  })
}

export function useClosePdo(pdoId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: { closure_date: string; notes?: string }) => {
      const res = await api.post<ApiResponse<PdoHeader>>(`/pdo/${pdoId}/close`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pdo'] }),
  })
}
