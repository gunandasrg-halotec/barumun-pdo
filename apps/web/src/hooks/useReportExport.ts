import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api, getApiErrorMessage } from '@/lib/api'
import { useToastStore } from '@/store/toast.store'
import type { ExportFormat, ExportJobState, ReportFilters, ReportType } from '@/types/report'

interface ExportParams {
  report_type: ReportType
  format: ExportFormat
  filters: ReportFilters
}

export function useReportExport() {
  const toast  = useToastStore((s) => s.push)
  const [jobId, setJobId] = useState<string | null>(null)

  const exportMut = useMutation({
    mutationFn: async ({ report_type, format, filters }: ExportParams) => {
      const res = await api.post<{ success: boolean; data: { job_id: string } }>(
        '/reports/export',
        { report_type, format, ...filters }
      )
      return res.data.data.job_id
    },
    onSuccess: (id) => {
      setJobId(id)
      toast('Export sedang diproses, harap tunggu…', 'info')
    },
    onError: (err) => {
      toast(getApiErrorMessage(err), 'error')
    },
  })

  const statusQuery = useQuery<ExportJobState>({
    queryKey: ['export-status', jobId],
    queryFn: async () => {
      const res = await api.get<{ success: boolean; data: ExportJobState }>(
        `/reports/export/${jobId}`
      )
      return res.data.data
    },
    enabled: !!jobId,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      if (status === 'done' || status === 'failed') return false
      return 2000
    },
    staleTime: 0,
  })

  // Auto-open download when done
  const jobStatus = statusQuery.data
  if (jobStatus?.status === 'done' && jobStatus.url) {
    window.open(jobStatus.url, '_blank')
    setJobId(null) // reset so next export can start fresh
  }
  if (jobStatus?.status === 'failed') {
    toast(jobStatus.error ?? 'Export gagal.', 'error')
    setJobId(null)
  }

  const startExport = (params: ExportParams) => exportMut.mutate(params)

  const isExporting =
    exportMut.isPending ||
    (!!jobId && (jobStatus?.status === 'queued' || jobStatus?.status === 'processing'))

  return { startExport, isExporting, jobStatus, jobId }
}
