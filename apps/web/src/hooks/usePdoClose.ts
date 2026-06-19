import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, getApiErrorMessage } from '@/lib/api'
import { useToastStore } from '@/store/toast.store'
import type { ApiResponse } from '@/types'

interface ClosePayload {
  closed_date:    string
  closure_notes?: string
}

interface CloseResult {
  pdo_number:   string
  status:       string
  closure_type: string
  closed_at:    string
  closed_by:    { id: string; full_name: string } | null
}

export function usePdoClose(pdoId: string, options?: { onSuccess?: () => void }) {
  const qc   = useQueryClient()
  const toast = useToastStore((s) => s.push)

  return useMutation({
    mutationFn: async (data: ClosePayload) => {
      const res = await api.post<ApiResponse<CloseResult>>(`/pdo/${pdoId}/close`, data)
      return res.data
    },
    onSuccess: (data) => {
      toast(data.message ?? 'PDO berhasil ditutup.', 'success')
      qc.invalidateQueries({ queryKey: ['pdo', pdoId] })
      qc.invalidateQueries({ queryKey: ['pdo-list'] })
      options?.onSuccess?.()
    },
    onError: (err) => {
      toast(getApiErrorMessage(err), 'error')
    },
  })
}
