import { useState, useCallback } from 'react'
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
import { DetailAttachmentPanel, AttachmentBadge } from '@/components/pdo/DetailAttachmentPanel'

export function PdoDetailPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const user     = useAuthStore((s) => s.user)
  const role     = user?.role.code as RoleCode | undefined

  const [showCloseModal, setShowCloseModal]     = useState(false)
  const [collapsedCats, setCollapsedCats]       = useState<Set<string>>(new Set())
  const [collapsedSubs, setCollapsedSubs]       = useState<Set<string>>(new Set())
  const [isDownloading, setIsDownloading]       = useState(false)
  const [openAttachmentId, setOpenAttachmentId] = useState<string | null>(null)

  const toggleAttachment = (detailId: string) =>
    setOpenAttachmentId((prev) => (prev === detailId ? null : detailId))

  const handleExport = async () => {
    if (!id) return
    setIsDownloading(true)
    try {
      const res = await api.get(`/pdo/${id}/export`, { responseType: 'blob' })
      const url  = URL.createObjectURL(new Blob([res.data]))
      const a    = document.createElement('a')
      a.href     = url
      a.download = `PDO-${pdo?.pdo_number ?? id}.xlsx`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      toast('Gagal mengunduh file Excel. Coba lagi.', 'error')
    } finally {
      setIsDownloading(false)
    }
  }

  const toggleCat = useCallback((key: string) => {
    setCollapsedCats((prev) => {
      const next = new Set(prev)
      next.has(key) ? next.delete(key) : next.add(key)
      return next
    })
  }, [])

  const toggleSub = useCallback((key: string) => {
    setCollapsedSubs((prev) => {
      const next = new Set(prev)
      next.has(key) ? next.delete(key) : next.add(key)
      return next
    })
  }, [])

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
          <Button variant="secondary" loading={isDownloading} onClick={handleExport}>
            <CloudDownload className="w-4 h-4" /> Unduh Excel
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

      {/* Detail Table — grouped by kategori > sub-kategori, collapsible */}
      <div className="card">
        <h3 className="text-[17px] font-[850] mb-4">Rencana Biaya</h3>
        {!details?.length ? (
          <EmptyState message="Tidak ada item biaya." />
        ) : (() => {
          // Group details by category then subcategory
          type CatGroup = {
            catKey: string
            catLabel: string
            catOrder: number
            subs: {
              subKey: string
              subLabel: string
              subOrder: number
              items: PdoDetail[]
            }[]
          }
          const catMap = new Map<string, CatGroup>()
          for (const d of details) {
            const sub  = d.expense_item?.subcategory
            const cat  = sub?.category
            const catKey   = cat?.id  ?? '__no_cat'
            const subKey   = sub?.id  ?? '__no_sub'
            const catLabel = cat ? `${cat.code} — ${cat.name}` : 'Tanpa Kategori'
            const subLabel = sub ? `${sub.code} — ${sub.name}` : 'Tanpa Sub-Kategori'
            if (!catMap.has(catKey)) {
              catMap.set(catKey, { catKey, catLabel, catOrder: cat?.display_order ?? 999, subs: [] })
            }
            const cg = catMap.get(catKey)!
            let sg = cg.subs.find((s) => s.subKey === subKey)
            if (!sg) {
              sg = { subKey, subLabel, subOrder: sub?.display_order ?? 999, items: [] }
              cg.subs.push(sg)
            }
            sg.items.push(d)
          }
          const groups = [...catMap.values()].sort((a, b) => a.catOrder - b.catOrder)
          groups.forEach((g) => g.subs.sort((a, b) => a.subOrder - b.subOrder))

          return (
            <div className="overflow-auto">
              <table className="w-full border-collapse" style={{ minWidth: 860 }}>
                <thead>
                  <tr>
                    {['Kategori / Item Biaya', 'Deskripsi', 'Vol', 'Satuan', 'Rate', 'Jumlah', 'Transfer', 'Realisasi', 'Saldo', ''].map((h) => (
                      <th key={h} className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] sticky top-0">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {groups.map((g) => {
                    const catCollapsed = collapsedCats.has(g.catKey)
                    const catTotal     = g.subs.reduce((s, sg) => s + sg.items.reduce((ss, d) => ss + d.amount, 0), 0)
                    return (
                      <>
                        {/* Kategori row */}
                        <tr key={`cat-${g.catKey}`} className="border-t border-line bg-[#f0f4f0] cursor-pointer select-none" onClick={() => toggleCat(g.catKey)}>
                          <td colSpan={10} className="px-3 py-2 text-[12px] font-[850] text-ink">
                            <div className="flex items-center gap-2">
                              <span className={`transition-transform duration-150 text-muted ${catCollapsed ? '' : 'rotate-90'}`} style={{ display: 'inline-block' }}>▶</span>
                              <span>{g.catLabel}</span>
                              <span className="ml-auto text-[11px] font-[700] text-muted pr-2">{fmt(catTotal)}</span>
                            </div>
                          </td>
                        </tr>

                        {!catCollapsed && g.subs.map((sg) => {
                          const subCollapsed = collapsedSubs.has(sg.subKey)
                          const subTotal     = sg.items.reduce((s, d) => s + d.amount, 0)
                          return (
                            <>
                              {/* Sub-kategori row */}
                              <tr key={`sub-${sg.subKey}`} className="border-t border-line bg-[#f7faf7] cursor-pointer select-none" onClick={() => toggleSub(sg.subKey)}>
                                <td colSpan={10} className="pl-8 pr-3 py-1.5 text-[11px] font-[850] uppercase tracking-wider text-muted">
                                  <div className="flex items-center gap-2">
                                    <span className={`transition-transform duration-150 ${subCollapsed ? '' : 'rotate-90'}`} style={{ display: 'inline-block', fontSize: 10 }}>▶</span>
                                    <span>{sg.subLabel}</span>
                                    <span className="ml-auto text-[11px] font-[700] pr-2">{fmt(subTotal)}</span>
                                  </div>
                                </td>
                              </tr>

                              {!subCollapsed && sg.items.map((d) => {
                                const canUpload = role === 'KERANI' && pdo.status === 'draft'
                                const attachOpen = openAttachmentId === d.id
                                return (
                                  <>
                                    <tr key={d.id} className="border-t border-line hover:bg-[#fbfdfb]">
                                      <td className="pl-10 pr-3 py-2.5 text-sm font-bold">
                                        <div className="flex items-center gap-2 flex-wrap">
                                          {d.expense_item?.code && (
                                            <span className="text-[10px] font-[700] px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-100 whitespace-nowrap">
                                              {d.expense_item.code}
                                            </span>
                                          )}
                                          <span>{d.expense_item?.name ?? d.description ?? '—'}</span>
                                          {d.expense_item?.mode_input === 'auto_external' && (
                                            <button
                                              type="button"
                                              className="inline-flex items-center gap-1 text-[11px] font-[700] px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 transition-colors"
                                              onClick={() => toast('Fitur ambil data eksternal belum tersedia', 'error')}
                                            >
                                              <CloudDownload className="w-3 h-3" /> Ambil Eksternal
                                            </button>
                                          )}
                                        </div>
                                      </td>
                                      <td className="px-3 py-2.5 text-sm text-muted">{d.description}</td>
                                      <td className="px-3 py-2.5 text-sm">{d.quantity ?? '—'}</td>
                                      <td className="px-3 py-2.5 text-sm">{d.unit ?? '—'}</td>
                                      <td className="px-3 py-2.5 text-sm">{d.rate ? fmt(d.rate) : '—'}</td>
                                      <td className="px-3 py-2.5 text-sm font-bold">{fmt(d.amount)}</td>
                                      <td className="px-3 py-2.5 text-sm">{fmt(d.total_transferred ?? 0)}</td>
                                      <td className="px-3 py-2.5 text-sm">{fmt(d.total_realized ?? 0)}</td>
                                      <td className="px-3 py-2.5 text-sm font-bold text-green">
                                        {fmt((d.total_transferred ?? 0) - (d.total_realized ?? 0))}
                                      </td>
                                      <td className="px-3 py-2.5">
                                        <AttachmentBadge detailId={d.id} onClick={() => toggleAttachment(d.id)} />
                                      </td>
                                    </tr>
                                    {attachOpen && (
                                      <DetailAttachmentPanel
                                        key={`att-${d.id}`}
                                        detailId={d.id}
                                        canUpload={canUpload}
                                        colSpan={10}
                                      />
                                    )}
                                  </>
                                )
                              })}
                            </>
                          )
                        })}
                      </>
                    )
                  })}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-line bg-[#f7faf7]">
                    <td colSpan={6} className="px-3 py-2.5 text-[12px] font-[950] text-muted">Total</td>
                    <td className="px-3 py-2.5 font-[950]">{fmt(totalAmount)}</td>
                    <td className="px-3 py-2.5 font-[950]">{fmt(totalTransf)}</td>
                    <td className="px-3 py-2.5 font-[950]">{fmt(totalReal)}</td>
                    <td className="px-3 py-2.5 font-[950] text-green">{fmt(saldo)}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          )
        })()}
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
