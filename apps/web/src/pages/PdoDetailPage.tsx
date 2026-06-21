import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { usePdo } from '@/hooks/usePdo'
import { useAuthStore } from '@/store/auth.store'
import { PdoStatusBadge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { ClosePdoModal } from '@/components/pdo/ClosePdoModal'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate, fmtPeriode } from '@/lib/format'
import { isKerani } from '@/lib/auth'
import { api } from '@/lib/api'
import type { PdoDetail, ApiResponse, RoleCode } from '@/types'
import { ArrowLeft, GitBranch, Lock, CloudDownload } from 'lucide-react'

export function PdoDetailPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const user     = useAuthStore((s) => s.user)
  const role     = user?.role.code as RoleCode | undefined

  const [showCloseModal, setShowCloseModal] = useState(false)

  const { data: pdo, isLoading } = usePdo(id)

  const { data: details } = useQuery({
    queryKey: ['pdo-details', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoDetail[]>>(`/pdo/${id}/details`)
      return res.data.data
    },
    enabled: !!id,
  })

  const submitMut = useMutation({
    mutationFn: () => api.post(`/pdo/${id}/submit`, {
      submission_date: new Date().toISOString().split('T')[0],
    }),
    onSuccess: () => {
      toast('PDO berhasil diajukan')
      qc.invalidateQueries({ queryKey: ['pdo', id] })
      navigate(`/pdo/${id}/approval`)
    },
    onError: () => toast('Gagal mengajukan PDO', 'error'),
  })

  if (isLoading) return <div className="text-muted text-sm">Memuat...</div>
  if (!pdo) return <div className="text-muted text-sm">PDO tidak ditemukan.</div>

  const totalAmount  = details?.reduce((s, d) => s + d.amount, 0) ?? 0
  const totalTransf  = details?.reduce((s, d) => s + (d.total_transferred ?? 0), 0) ?? 0
  const totalReal    = details?.reduce((s, d) => s + (d.total_realized ?? 0), 0) ?? 0
  const saldo        = totalTransf - totalReal

  return (
    <div className="max-w-4xl">
      {/* Hero */}
      <div className="flex items-start justify-between mb-6">
        <div>
          <div className="flex items-center gap-3 mb-1">
            <Button variant="secondary" size="sm" onClick={() => navigate('/pdo')}>
              <ArrowLeft className="w-4 h-4" />
            </Button>
            <h2 className="text-[28px] font-[950] text-ink">{pdo.pdo_number}</h2>
            <PdoStatusBadge
              status={pdo.status}
              onClick={() => navigate(`/pdo/${pdo.id}/approval`)}
            />
          </div>
          <p className="text-muted text-sm ml-10">
            {fmtPeriode(pdo.period_month, pdo.period_year)} · {pdo.plantation_unit?.name ?? '—'} · Dibuat {fmtDate(pdo.created_at)}
          </p>
        </div>
        <div className="flex gap-2">
          {role && isKerani(role) && pdo.status === 'draft' && (
            <>
              <Button variant="secondary" onClick={() => navigate(`/pdo/${pdo.id}/edit`)}>Edit</Button>
              <Button loading={submitMut.isPending} onClick={() => submitMut.mutate()}>
                Ajukan ke Asisten
              </Button>
            </>
          )}
          {/* BR-CLOSE-002: Tombol Tutup PDO hanya untuk MANAJER_KEUANGAN saat status final */}
          {role === 'MANAJER_KEUANGAN' && pdo.status === 'final' && (
            <Button
              className="bg-red text-white hover:bg-red/90"
              onClick={() => setShowCloseModal(true)}
            >
              <Lock className="w-4 h-4" /> Tutup PDO
            </Button>
          )}
          <Button variant="secondary" onClick={() => navigate(`/pdo/${pdo.id}/approval`)}>
            <GitBranch className="w-4 h-4" /> Approval
          </Button>
        </div>
      </div>

      {/* KPI Row */}
      <div className="grid grid-cols-2 desk:grid-cols-4 gap-4 mb-5">
        {[
          { label: 'Total Pengajuan', val: totalAmount },
          { label: 'Total Transfer',  val: totalTransf },
          { label: 'Total Realisasi', val: totalReal },
          { label: 'Saldo',           val: saldo },
        ].map((k) => (
          <div key={k.label} className="card text-center">
            <div className="text-[11px] font-[850] text-muted uppercase tracking-wider mb-1">{k.label}</div>
            <div className="text-[20px] font-[950] text-ink">{fmt(k.val)}</div>
          </div>
        ))}
      </div>

      {/* Detail Table */}
      <div className="card">
        <h3 className="text-[17px] font-[850] mb-4">Rencana Biaya</h3>
        {!details?.length ? (
          <EmptyState message="Tidak ada item biaya." />
        ) : (
          <div className="overflow-auto">
            <table className="w-full border-collapse" style={{ minWidth: 800 }}>
              <thead>
                <tr>
                  {['Item Biaya', 'Deskripsi', 'Vol', 'Satuan', 'Rate', 'Jumlah', 'Transfer', 'Realisasi', 'Saldo'].map((h) => (
                    <th key={h} className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {details.map((d) => (
                  <tr key={d.id} className="border-t border-line hover:bg-[#fbfdfb]">
                    <td className="px-3 py-2.5 text-sm font-bold">
                      <div className="flex items-center gap-2">
                        <span>{d.expense_item?.name ?? d.description ?? '—'}</span>
                        {d.expense_item?.mode_input === 'auto_external' && (
                          <button
                            type="button"
                            title="Ambil dari Sistem Lain"
                            className="inline-flex items-center gap-1 text-[11px] font-[700] px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 transition-colors"
                            onClick={() => toast('Fitur ambil data eksternal belum tersedia', 'error')}
                          >
                            <CloudDownload className="w-3 h-3" /> Ambil Eksternal
                          </button>
                        )}
                      </div>
                    </td>
                    <td className="px-3 py-2.5 text-sm">{d.description}</td>
                    <td className="px-3 py-2.5 text-sm">{d.quantity ?? '—'}</td>
                    <td className="px-3 py-2.5 text-sm">{d.unit ?? '—'}</td>
                    <td className="px-3 py-2.5 text-sm">{d.rate ? fmt(d.rate) : '—'}</td>
                    <td className="px-3 py-2.5 text-sm font-bold">{fmt(d.amount)}</td>
                    <td className="px-3 py-2.5 text-sm">{fmt(d.total_transferred ?? 0)}</td>
                    <td className="px-3 py-2.5 text-sm">{fmt(d.total_realized ?? 0)}</td>
                    <td className="px-3 py-2.5 text-sm font-bold text-green">
                      {fmt((d.total_transferred ?? 0) - (d.total_realized ?? 0))}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-line bg-[#f7faf7]">
                  <td colSpan={5} className="px-3 py-2.5 text-[12px] font-[950] text-muted">Total</td>
                  <td className="px-3 py-2.5 font-[950]">{fmt(totalAmount)}</td>
                  <td className="px-3 py-2.5 font-[950]">{fmt(totalTransf)}</td>
                  <td className="px-3 py-2.5 font-[950]">{fmt(totalReal)}</td>
                  <td className="px-3 py-2.5 font-[950] text-green">{fmt(saldo)}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </div>

      {pdo.status === 'closed' && pdo.closed_at && (
        <div className="card mt-4 border border-[#e5e7eb] bg-[#f9fafb]">
          <p className="text-sm text-muted">
            PDO ini ditutup pada <strong>{fmtDate(pdo.closed_at)}</strong>
            {pdo.closure_type === 'manual' ? ` oleh ${pdo.closer?.full_name ?? 'Manajer Keuangan'}` : ' secara otomatis (sistem)'}.
          </p>
        </div>
      )}

      {pdo.notes && (
        <div className="card mt-4">
          <h4 className="text-[13px] font-[850] text-muted mb-1">Catatan</h4>
          <p className="text-sm">{pdo.notes}</p>
        </div>
      )}

      {id && pdo && (
        <ClosePdoModal
          isOpen={showCloseModal}
          pdoId={id}
          periodYear={pdo.period_year}
          periodMonth={pdo.period_month}
          onSuccess={() => {
            setShowCloseModal(false)
            qc.invalidateQueries({ queryKey: ['pdo', id] })
          }}
          onClose={() => setShowCloseModal(false)}
        />
      )}
    </div>
  )
}
