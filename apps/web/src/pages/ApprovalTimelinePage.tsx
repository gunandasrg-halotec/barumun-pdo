import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { usePdo, usePdoApprovalHistory, useApprovePdo, useRejectPdo } from '@/hooks/usePdo'
import { useAuthStore } from '@/store/auth.store'
import { PdoStatusBadge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { fmtDate, fmtPeriode } from '@/lib/format'
import { useToastStore } from '@/store/toast.store'
import { canApprove } from '@/lib/auth'
import type { RoleCode } from '@/types'
import { ArrowLeft, CheckCircle, XCircle, Clock, AlertCircle } from 'lucide-react'

const STAGES = [
  { key: 'draft',              label: 'Draft — dibuat Kerani' },
  { key: 'submitted',          label: 'Submitted — Kerani submit' },
  { key: 'reviewed_asisten',   label: 'Reviewed Asisten' },
  { key: 'in_review_manager',  label: 'In Review Manajer (paralel: Kebun + Keuangan)' },
  { key: 'in_review_direktur', label: 'In Review Direktur' },
  { key: 'final',              label: 'Final — Direktur approve' },
  { key: 'closed',             label: 'Closed — sistem / Manajer Keuangan' },
]

const STATUS_ORDER = ['draft', 'submitted', 'reviewed_asisten', 'in_review_manager', 'in_review_direktur', 'final', 'closed']

export function ApprovalTimelinePage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const user     = useAuthStore((s) => s.user)
  const toast    = useToastStore((s) => s.push)
  const role     = user?.role.code as RoleCode | undefined

  const { data: pdo, isLoading }  = usePdo(id)
  const { data: logs }            = usePdoApprovalHistory(id)
  const approveMut                = useApprovePdo(id ?? '')
  const rejectMut                 = useRejectPdo(id ?? '')

  const [showModal, setShowModal]   = useState(false)
  const [modalType, setModalType]   = useState<'approve' | 'reject'>('approve')
  const [reason, setReason]         = useState('')

  const currentStatusIndex = STATUS_ORDER.indexOf(pdo?.status ?? '')
  const userCanApprove     = role ? canApprove(role) : false

  const handleAction = async () => {
    if (modalType === 'reject' && !reason.trim()) return
    try {
      if (modalType === 'approve') {
        await approveMut.mutateAsync({ reason: reason || undefined })
        toast('PDO berhasil disetujui')
      } else {
        await rejectMut.mutateAsync({ reason })
        toast('PDO dikembalikan ke Kerani')
      }
      setShowModal(false)
      setReason('')
    } catch {
      toast('Gagal memproses approval', 'error')
    }
  }

  if (isLoading) {
    return <div className="text-muted text-sm">Memuat...</div>
  }

  return (
    <div className="max-w-2xl">
      {/* Hero */}
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Approval Timeline</h2>
          <p className="text-muted text-sm mt-1">
            {pdo?.pdo_number} — {pdo && fmtPeriode(pdo.period_month, pdo.period_year)}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="secondary" onClick={() => navigate('/pdo')}>
            <ArrowLeft className="w-4 h-4" /> Kembali
          </Button>
          {userCanApprove && pdo && !['final', 'closed', 'draft'].includes(pdo.status) && (
            <>
              <Button
                variant="danger"
                onClick={() => { setModalType('reject'); setShowModal(true) }}
              >
                <XCircle className="w-4 h-4" /> Reject
              </Button>
              <Button
                onClick={() => { setModalType('approve'); setShowModal(true) }}
              >
                <CheckCircle className="w-4 h-4" /> Approve
              </Button>
            </>
          )}
        </div>
      </div>

      {pdo && (
        <div className="mb-6">
          <PdoStatusBadge status={pdo.status} />
        </div>
      )}

      {/* Timeline */}
      <div className="flex flex-col gap-4">
        {STAGES.map((stage, idx) => {
          const isDone    = idx < currentStatusIndex
          const isActive  = idx === currentStatusIndex
          const isPending = idx > currentStatusIndex

          const relevantLogs = logs?.filter((l) => l.approval_stage === stage.key) ?? []
          const hasReject    = relevantLogs.some((l) => l.action === 'reject')

          return (
            <div key={stage.key} className="grid gap-3" style={{ gridTemplateColumns: '36px 1fr' }}>
              {/* Dot */}
              <div className="flex flex-col items-center">
                <div
                  className={`w-[34px] h-[34px] rounded-full flex items-center justify-center text-sm font-[950] shrink-0 ${
                    isDone    ? 'bg-[#ddf8e9] text-green' :
                    isActive  ? 'bg-green text-white' :
                    hasReject ? 'bg-[#fee2e2] text-red' :
                    'bg-[#f1f5f9] text-[#94a3b8]'
                  }`}
                >
                  {isDone ? <CheckCircle className="w-4 h-4" /> :
                   isActive ? idx + 1 :
                   hasReject ? <XCircle className="w-4 h-4" /> :
                   <Clock className="w-4 h-4" />}
                </div>
                {idx < STAGES.length - 1 && (
                  <div className={`w-0.5 flex-1 mt-1 ${isDone ? 'bg-green2' : 'bg-line'}`} style={{ minHeight: 20 }} />
                )}
              </div>

              {/* Card */}
              <div className={`rounded-card border p-4 mb-2 ${
                isActive  ? 'border-green bg-mint' :
                hasReject ? 'border-red bg-[#fff5f5]' :
                isDone    ? 'border-line bg-white' :
                'border-line bg-[#f9fafb]'
              }`}>
                <div className="font-[850] text-sm text-ink">{stage.label}</div>

                {relevantLogs.map((log) => (
                  <div key={log.id} className="mt-2 text-sm text-muted">
                    <span className="font-semibold text-ink">{log.actor?.full_name ?? '—'}</span>
                    {' · '}{fmtDate(log.created_at)}
                    {log.reason && (
                      <div className={`mt-1 text-sm px-2 py-1 rounded ${
                        log.action === 'reject' ? 'bg-[#fee2e2] text-[#b91c1c]' : 'bg-mint text-green'
                      }`}>
                        {log.action === 'reject' ? '✗ ' : '✓ '}{log.reason}
                      </div>
                    )}
                  </div>
                ))}

                {isPending && !hasReject && (
                  <div className="mt-2 text-xs text-muted flex items-center gap-1">
                    <Clock className="w-3 h-3" /> Belum dimulai
                  </div>
                )}
              </div>
            </div>
          )
        })}
      </div>

      {/* Modal Approve/Reject */}
      <Modal
        open={showModal}
        onClose={() => { setShowModal(false); setReason('') }}
        title={modalType === 'approve' ? 'Approve PDO' : 'Reject PDO'}
      >
        <p className="text-sm text-muted mb-4">
          {modalType === 'approve'
            ? 'Isi catatan jika diperlukan (opsional).'
            : 'Isi alasan penolakan untuk Kerani (wajib).'}
        </p>
        <textarea
          className="input-base resize-none w-full"
          rows={4}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder={modalType === 'approve' ? 'Catatan approval...' : 'Alasan penolakan...'}
        />
        {modalType === 'reject' && !reason.trim() && (
          <div className="flex items-center gap-1 text-xs text-red mt-1">
            <AlertCircle className="w-3 h-3" /> Alasan penolakan wajib diisi.
          </div>
        )}
        <div className="flex justify-end gap-2 mt-5">
          <Button variant="secondary" onClick={() => setShowModal(false)}>Batal</Button>
          {modalType === 'reject' ? (
            <Button
              variant="danger"
              loading={rejectMut.isPending}
              disabled={!reason.trim()}
              onClick={handleAction}
            >
              Reject
            </Button>
          ) : (
            <Button loading={approveMut.isPending} onClick={handleAction}>
              Approve
            </Button>
          )}
        </div>
      </Modal>
    </div>
  )
}
