import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, getApiErrorMessage } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate, fmtPeriode } from '@/lib/format'
import { canApprove, isKerani } from '@/lib/auth'
import { ArrowLeft, CheckCircle, XCircle, AlertCircle, Clock, Circle } from 'lucide-react'
import type { ApiResponse, PdoSupplementaryHeader, RoleCode } from '@/types'

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

const STATUS_LABEL: Record<string, string> = {
  draft: 'Draft', submitted: 'Diajukan', reviewed_asisten: 'Disetujui Asisten',
  in_review_manager: 'Menunggu Manajer (paralel)', in_review_direktur: 'Disetujui Kedua Manajer',
  final_merged: 'Disetujui Direktur', rejected: 'Ditolak',
}

// Each step: the status achieved after that approver approves
const PIPELINE: { role: string; label: string; doneStatus: string }[] = [
  { role: 'KERANI',            label: 'Pengajuan oleh Kerani',       doneStatus: 'submitted'          },
  { role: 'ASISTEN_KEBUN',     label: 'Review Asisten Kebun',        doneStatus: 'reviewed_asisten'   },
  { role: 'MANAJER_KEBUN',     label: 'Review Manajer (paralel)',    doneStatus: 'in_review_manager'  },
  { role: 'MANAJER_KEUANGAN',  label: 'Kedua Manajer Disetujui',     doneStatus: 'in_review_direktur' },
  { role: 'DIREKTUR_KEUANGAN', label: 'Persetujuan Direktur Keuangan', doneStatus: 'final_merged'     },
]

const STATUS_ORDER = ['draft', 'submitted', 'reviewed_asisten', 'in_review_manager', 'in_review_direktur', 'final_merged']

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

  const details = supp?.details

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
    mutationFn: () => api.post(`/pdo-supplementary/${id}/submit`, {
      submission_date: new Date().toISOString().split('T')[0],
    }),
    onSuccess: () => {
      toast('PDO Tambahan berhasil diajukan')
      qc.invalidateQueries({ queryKey: ['pdo-supplementary', id] })
    },
    onError: () => toast('Gagal mengajukan', 'error'),
  })

  const onApprovalSettled = () => {
    qc.invalidateQueries({ queryKey: ['pdo-supplementary', id] })
    qc.invalidateQueries({ queryKey: ['pdo-supplementary-logs', id] })
    setShowModal(false)
    setReason('')
  }

  const approveMut = useMutation({
    mutationFn: (reason?: string) => api.post(`/pdo-supplementary/${id}/approve`, { reason }),
    onSuccess: () => { toast('Berhasil disetujui'); onApprovalSettled() },
    onError: (error) => toast(getApiErrorMessage(error), 'error'),
  })

  const rejectMut = useMutation({
    mutationFn: (reason: string) => api.post(`/pdo-supplementary/${id}/reject`, { reason }),
    onSuccess: () => { toast('Berhasil ditolak/dikembalikan'); onApprovalSettled() },
    onError: (error) => toast(getApiErrorMessage(error), 'error'),
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
          {userCanApprove && !['final_merged', 'rejected', 'draft'].includes(supp.status) && (() => {
            // BR-APPR-002: Sembunyikan tombol Approve jika manajer ini sudah approve di tahap paralel
            const isManagerStage = ['reviewed_asisten', 'in_review_manager'].includes(supp.status)
            const alreadyApproved = isManagerStage && (
              (role === 'MANAJER_KEBUN'    && supp.manager_kebun_approved === true) ||
              (role === 'MANAJER_KEUANGAN' && supp.manager_keuangan_approved === true)
            )
            return (
              <>
                <Button variant="danger" onClick={() => { setModalType('reject'); setShowModal(true) }}>
                  <XCircle className="w-4 h-4" /> Reject
                </Button>
                {!alreadyApproved && (
                  <Button onClick={() => { setModalType('approve'); setShowModal(true) }}>
                    <CheckCircle className="w-4 h-4" /> Approve
                  </Button>
                )}
                {alreadyApproved && (
                  <span className="text-sm text-green font-semibold flex items-center gap-1">
                    <CheckCircle className="w-4 h-4" /> Sudah disetujui — menunggu manajer lain
                  </span>
                )}
              </>
            )
          })()}
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
                    <td className="px-3 py-2 text-sm text-muted italic">{d.notes ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Approval Pipeline */}
      <div className="card mb-4">
        <h3 className="text-[17px] font-[850] mb-5">Status Persetujuan</h3>
        {supp.status === 'rejected' ? (
          <div className="flex items-center gap-2 text-sm text-red font-bold">
            <XCircle className="w-5 h-5 shrink-0" />
            Ditolak — {STATUS_LABEL[supp.status]}
            {logs?.find((l) => l.action === 'reject') && (
              <span className="font-normal text-muted ml-2">
                Alasan: {logs.find((l) => l.action === 'reject')?.reason ?? '—'}
              </span>
            )}
          </div>
        ) : (
          <div className="flex items-start gap-0">
            {PIPELINE.map((step, i) => {
              const currentIdx = STATUS_ORDER.indexOf(supp.status)
              const stepIdx    = STATUS_ORDER.indexOf(step.doneStatus)
              const isDone     = currentIdx >= stepIdx
              const isCurrent  = STATUS_ORDER.indexOf(step.doneStatus) === currentIdx
              const log        = logs?.find((l) => l.approval_stage && step.doneStatus.startsWith(l.approval_stage.split('_')[0]))

              return (
                <div key={step.role} className="flex-1 flex flex-col items-center text-center relative">
                  {/* Connector line (except first) */}
                  {i > 0 && (
                    <div className={`absolute left-0 top-[14px] w-1/2 h-0.5 ${isDone || isCurrent ? 'bg-green' : 'bg-line'}`} />
                  )}
                  {/* Connector line (except last) */}
                  {i < PIPELINE.length - 1 && (
                    <div className={`absolute right-0 top-[14px] w-1/2 h-0.5 ${isDone && !isCurrent ? 'bg-green' : 'bg-line'}`} />
                  )}
                  {/* Icon */}
                  <div className="relative z-10 mb-2">
                    {isDone ? (
                      <CheckCircle className="w-7 h-7 text-green" />
                    ) : isCurrent ? (
                      <Clock className="w-7 h-7 text-amber-400" />
                    ) : (
                      <Circle className="w-7 h-7 text-line" />
                    )}
                  </div>
                  <p className={`text-[11px] font-bold leading-tight px-1 ${isDone ? 'text-green' : isCurrent ? 'text-amber-500' : 'text-muted'}`}>
                    {step.label}
                  </p>
                  {log && (
                    <p className="text-[10px] text-muted mt-0.5">{log.actor?.full_name}</p>
                  )}
                </div>
              )
            })}
          </div>
        )}

        {/* BR-APPR-002: Panel status per-manajer di tahap paralel */}
        {['reviewed_asisten', 'in_review_manager'].includes(supp.status) && (
          <div className="mt-4 grid grid-cols-2 gap-2">
            {[
              { label: 'Manajer Kebun',    approved: supp.manager_kebun_approved },
              { label: 'Manajer Keuangan', approved: supp.manager_keuangan_approved },
            ].map(({ label, approved }) => (
              <div
                key={label}
                className={`flex items-center gap-2 text-xs px-2 py-1.5 rounded border ${
                  approved === true  ? 'bg-[#ddf8e9] border-green text-green' :
                  approved === false ? 'bg-[#fee2e2] border-red text-red' :
                  'bg-[#f1f5f9] border-line text-muted'
                }`}
              >
                {approved === true  ? <CheckCircle className="w-3 h-3 shrink-0" /> :
                 approved === false ? <XCircle className="w-3 h-3 shrink-0" /> :
                 <Clock className="w-3 h-3 shrink-0" />}
                {label}: {approved === true ? 'Disetujui' : approved === false ? 'Ditolak' : 'Menunggu'}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Riwayat Approval Log */}
      {logs && logs.length > 0 && (
        <div className="card">
          <h3 className="text-[17px] font-[850] mb-4">Riwayat Approval</h3>
          <div className="flex flex-col gap-2">
            {logs.map((log) => (
              <div key={log.id} className="flex items-start gap-3 text-sm">
                <div className={`w-2 h-2 rounded-full mt-1.5 shrink-0 ${
                  log.action === 'approve' || log.action === 'submit' || log.action === 'resubmit' ? 'bg-green' :
                  log.action === 'reject'  ? 'bg-red' : 'bg-amber-400'
                }`} />
                <div>
                  <span className="font-bold">{log.actor?.full_name ?? '—'}</span>
                  {' · '}
                  <span className="capitalize">{log.action.replace(/_/g, ' ')}</span>
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
              loading={rejectMut.isPending}
              onClick={() => rejectMut.mutate(reason)}
            >
              Reject
            </Button>
          ) : (
            <Button
              loading={approveMut.isPending}
              onClick={() => approveMut.mutate(reason || undefined)}
            >
              Approve
            </Button>
          )}
        </div>
      </Modal>
    </div>
  )
}
