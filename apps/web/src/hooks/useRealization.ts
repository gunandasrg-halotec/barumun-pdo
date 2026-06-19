import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { RealizationEntry, TransferEntry, ApiResponse } from '@/types'

// ─── Transfer ────────────────────────────────────────────────────────────────

export function useTransfers(pdoDetailId: string | undefined) {
  return useQuery({
    queryKey: ['transfers', pdoDetailId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<TransferEntry[]>>(`/pdo-details/${pdoDetailId}/transfers`)
      return res.data.data
    },
    enabled: !!pdoDetailId,
  })
}

export function useAddTransfer(pdoDetailId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<TransferEntry>>(`/pdo-details/${pdoDetailId}/transfers`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['transfers', pdoDetailId] }),
  })
}

export function useUpdateTransfer(entryId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.put<ApiResponse<TransferEntry>>(`/transfer-entries/${entryId}`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['transfers'] }),
  })
}

// ─── Realization ─────────────────────────────────────────────────────────────

export function useRealizationsByPdo(pdoId: string | undefined) {
  return useQuery({
    queryKey: ['realizations', 'pdo', pdoId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<RealizationEntry[]>>(`/pdo/${pdoId}/realizations/items`)
      return res.data.data
    },
    enabled: !!pdoId,
  })
}

export function useAddRealization() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<RealizationEntry>>('/realization-entries', data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['realizations'] }),
  })
}

export function useUpdateRealization(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.put<ApiResponse<RealizationEntry>>(`/realization-entries/${id}`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['realizations'] }),
  })
}

// ─── Attachment ──────────────────────────────────────────────────────────────

export function useUploadAttachment(entryId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('file', file)
      const res = await api.post(
        `/realization-entries/${entryId}/attachments`,
        formData,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      )
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['realizations'] }),
  })
}
