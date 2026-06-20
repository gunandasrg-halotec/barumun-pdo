import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate, fmtPeriode } from '@/lib/format'
import { canApprove, isKerani } from '@/lib/auth'
import { ArrowLeft, CheckCircle, XCircle, AlertCircle } from 'lucide-react'
import type { ApiResponse, PdoSupplementaryHeader, RoleCode } from '@/types'

interface SupplementaryDetail {
  id: string
  expense_item?: { name: string }
  description: string
  quantity: number | null
  unit: string | null
  rate: number | null
  amount: number
  justification: string
  notes: string | null
}

interface ApprovalLog {
  id: string
  actor?: { full_name: string }
  approval_stage: string
  action: string
  reason: string | null
  created_at: string
}

const STATUS_BADGE: Record<string, 'draft' | 'approved' | 'reject' | 'review' | 'purple'> = {
  draft: 'draft', submitted: 'review', reviewed_asisten: 'review',
  in_review_manager: 'review', in_review_direktur: 'review',
  final_merged: 'approved', rejected: 'reject',
}

export function PdoSupplementaryDetailPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const user     = useAuthStore((s) => s.user)
  const role     = user?.role.code as RoleCode | undefined

  const { data: supp, isLoading } = useQuery({
    queryKey: ['pdo-supplementary', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoSupplementaryHeader>>(`/pdo-supplementary/${id}`)
      return res.data.data
    },
    enabled: !!id,
  })

  const { data: details } = useQuery({
    queryKey: ['pdo-supplementary-details', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<SupplementaryDetail[]>>(`/pdo-supplementary/${id}/details`)
      return res.data.data
    },
    enabled: !!id,
  })

  const { data: logs } = useQuery({
    queryKey: ['pdo-supplementary-logs', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<ApprovalLog[]>>(`/pdo-supplementary/${id}/approval-logs`)
      return res.data.data
    },
    enabled: !!id,
  })

  const [showModal, setShowModal] = useState(false)
  const [modalType, setModalType] = useState<'approve' | 'reject'>('approve')
  const [reason, setReason]       = useState('')

  const submitMut = useMutation({
    mutationFn: () => api.post(`/pdo-supplementary/${id}/submit`),
    onSuccess: () => {
      toast('PDO Tambahan berhasil diajukan')
      qc.invalidateQueries({ queryKey: ['pdo-supplementary', id] })
    },
    onError: () => toast('Gagal mengajukan', 'error'),
  })

  const approvalMut = useMutation({
    mutationFn: ({ action, reason }: { action: string; reason?: string }) =>
      api.post(`/pdo-supplementary/${id}/approve`, { action, reason }),
    onSuccess: () => {
      toast(modalType === 'approve' ? 'Berhasil disetujui' : 'Berhasil ditolak/dikembalikan')
      qc.invalidateQueries({ queryKey: ['pdo-supplementary', id] })
      qc.invalidateQueries({ queryKey: ['pdo-supplementary-logs', id] })
      setShowModal(false)
      setReason('')
    },
    onError: () => toast('Gagal memproses approval', 'error'),
  })

  if (isLoading) return <div className="text-muted text-sm">Memuat...</div>
  if (!supp)     return <div className="text-muted text-sm">PDO Tambahan tidak ditemukan.</div>

  const totalAmount = details?.reduce((s, d) => s + d.amount, 0) ?? 0
  const userCanApprove = role ? canApprove(role) : false

  return (
    <div className="max-w-3xl">
      <div className="flex items-start justify-between mb-6">
        <div>
          <div className="flex items-center gap-3 mb-1">
            <Button variant="secondary" size="sm" onClick={() => navigate('/pdo-tambahan')}>
              <ArrowLeft className="w-4 h-4" />
            </Button>
            <h2 className="text-[28px] font-[950] text-ink">{supp.pdo_number}</h2>
            <Badge variant={STATUS_BADGE[supp.status] ?? 'draft'}>
              {supp.status.replace(/_/g, ' ')}
            </Badge>
          </div>
          <p className="text-muted text-sm ml-10">
            {fmtPeriode(supp.period_month, supp.period_year)}
          </p>
        </div>
        <div className="flex gap-2">
          {role && isKerani(role) && supp.status === 'draft' && (
            <>
              <Button variant="secondary" onClick={() => navigate(`/pdo-tambahan/${id}/edit`)}>Edit</Button>
              <Button loading={submitMut.isPending} onClick={() => submitMut.mutate()}>
                Ajukan ke Asisten
              </Button>
            </>
          )}
          {userCanApprove && !['final_merged', 'rejected', 'draft'].includes(supp.status) && (
            <>
              <Button variant="danger" onClick={() => { setModalType('reject'); setShowModal(true) }}>
                <XCircle className="w-4 h-4" /> Reject
              </Button>
              <Button onClick={() => { setModalType('approve'); setShowModal(true) }}>
                <CheckCircle className="w-4 h-4" /> Approve
              </Button>
            </>
          )}
        </div>
      </div>

      {/* Detail Table */}
      <div className="card mb-4">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-[17px] font-[850]">Item Biaya Tambahan</h3>
          <div className="text-[20px] font-[950] text-green">{fmt(totalAmount)}</div>
        </div>

        {!details?.length ? (
          <EmptyState />
        ) : (
          <div className="overflow-auto">
            <table className="w-full border-collapse">
              <thead>
                <tr>
                  {['Item', 'Deskripsi', 'Vol', 'Satuan', 'Rate', 'Jumlah', 'Justifikasi'].map((h) => (
                    <th key={h} className="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {details.map((d) => (
                  <tr key={d.id} className="border-t border-line hover:bg-[#fbfdfb]">
                    <td className="px-3 py-2 text-sm font-bold">{d.expense_item?.name ?? '—'}</td>
                    <td className="px-3 py-2 text-sm">{d.description}</td>
                    <td className="px-3 py-2 text-sm">{d.quantity ?? '—'}</td>
                    <td className="px-3 py-2 text-sm">{d.unit ?? '—'}</td>
                    <td className="px-3 py-2 text-sm">{d.rate ? fmt(d.rate) : '—'}</td>
                    <td className="px-3 py-2 text-sm font-bold">{fmt(d.amount)}</td>
                    <td className="px-3 py-2 text-sm text-muted italic">{d.justification}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Approval Log */}
      {logs && logs.length > 0 && (
        <div className="card">
          <h3 className="text-[17px] font-[850] mb-4">Riwayat Approval</h3>
          <div className="flex flex-col gap-2">
            {logs.map((log) => (
              <div key={log.id} className="flex items-start gap-3 text-sm">
                <div className={`w-2 h-2 rounded-full mt-1.5 shrink-0 ${
                  log.action === 'approve' ? 'bg-green' :
                  log.action === 'reject'  ? 'bg-red' : 'bg-amber-400'
                }`} />
                <div>
                  <span className="font-bold">{log.actor?.full_name ?? '—'}</span>
                  {' · '}{log.action} · {log.approval_stage}
                  {' · '}<span className="text-muted">{fmtDate(log.created_at)}</span>
                  {log.reason && (
                    <div className={`mt-1 text-xs px-2 py-1 rounded ${
                      log.action === 'reject' ? 'bg-[#fee2e2] text-red' : 'bg-mint text-green'
                    }`}>
                      {log.reason}
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Approval Modal */}
      <Modal
        open={showModal}
        onClose={() => { setShowModal(false); setReason('') }}
        title={modalType === 'approve' ? 'Approve PDO Tambahan' : 'Reject PDO Tambahan'}
      >
        <p className="text-sm text-muted mb-4">
          {modalType === 'approve' ? 'Isi catatan jika diperlukan (opsional).' : 'Isi alasan penolakan (wajib).'}
        </p>
        <textarea
          className="input-base resize-none w-full"
          rows={4}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder={modalType === 'approve' ? 'Catatan...' : 'Alasan penolakan...'}
        />
        {modalType === 'reject' && !reason.trim() && (
          <div className="flex items-center gap-1 text-xs text-red mt-1">
            <AlertCircle className="w-3 h-3" /> Alasan wajib diisi.
          </div>
        )}
        <div className="flex justify-end gap-2 mt-5">
          <Button variant="secondary" onClick={() => setShowModal(false)}>Batal</Button>
          {modalType === 'reject' ? (
            <Button
              variant="danger"
              disabled={!reason.trim()}
              loading={approvalMut.isPending}
              onClick={() => approvalMut.mutate({ action: 'reject', reason })}
            >
              Reject
            </Button>
          ) : (
            <Button
              loading={approvalMut.isPending}
              onClick={() => approvalMut.mutate({ action: 'approve', reason: reason || undefined })}
            >
              Approve
            </Button>
          )}
        </div>
      </Modal>
    </div>
  )
}
