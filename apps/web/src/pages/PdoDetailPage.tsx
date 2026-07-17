import { useState, useCallback } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { usePdoGrouped } from '@/hooks/usePdo'
import { useAuthStore } from '@/store/auth.store'
import { PdoStatusBadge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { ClosePdoModal } from '@/components/pdo/ClosePdoModal'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate, fmtPeriode } from '@/lib/format'
import { isKerani } from '@/lib/auth'
import { api } from '@/lib/api'
import type { PdoDetail, RoleCode } from '@/types'
import { ArrowLeft, GitBranch, Lock, CloudDownload, Search } from 'lucide-react'
import { DetailAttachmentPanel, AttachmentBadge } from '@/components/pdo/DetailAttachmentPanel'

export function PdoDetailPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const user     = useAuthStore((s) => s.user)
  const role     = user?.role.code as RoleCode | undefined

  const [showCloseModal, setShowCloseModal]         = useState(false)
  const [collapsedCats, setCollapsedCats]           = useState<Set<string>>(new Set())
  const [collapsedSubs, setCollapsedSubs]           = useState<Set<string>>(new Set())
  const [isDownloading, setIsDownloading]           = useState(false)
  const [openAttachmentId, setOpenAttachmentId]     = useState<string | null>(null)
  const [submitWarningRows, setSubmitWarningRows]   = useState<number[]>([])
  const [itemSearch, setItemSearch]                 = useState('')
  const [filterAutoExternal, setFilterAutoExternal] = useState(false)
  const [filterZeroAmount, setFilterZeroAmount]     = useState(false)

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

  const { data: grouped, isFetching, isError, refetch } = usePdoGrouped(id)
  const pdo              = grouped?.pdo
  const details          = grouped?.categories.flatMap((c) => c.subcategories.flatMap((s) => s.details))
  const suppGroups       = grouped?.supplementary_groups ?? []
  const suppDetails      = suppGroups.flatMap((g) => g.details)
  const allDetails       = [...(details ?? []), ...suppDetails]

  const submitMut = useMutation({
    mutationFn: () => api.post(`/pdo/${id}/submit`, {
      submission_date: new Date().toISOString().split('T')[0],
    }),
    onSuccess: () => {
      toast('PDO berhasil diajukan')
      qc.invalidateQueries({ queryKey: ['pdo', id] })
      navigate(`/pdo/${id}/approval`)
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { error?: { message?: string } } } })
        ?.response?.data?.error?.message
      toast(msg || 'Gagal mengajukan PDO', 'error')
    },
  })

  const handleAjukan = () => {
    const warningRows = (details ?? [])
      .filter((d) => d.is_auto_external_active && (d.needs_pull || d.is_stale_external_snapshot))
      .map((d) => d.display_order)
    if (warningRows.length > 0) {
      setSubmitWarningRows(warningRows)
    } else {
      submitMut.mutate()
    }
  }

  if (!pdo && isFetching) return <div className="text-muted text-sm">Memuat...</div>
  if (isError && !pdo) return (
    <div className="flex flex-col items-center justify-center gap-3 py-12 text-center">
      <p className="text-sm text-muted">Gagal memuat data PDO. Periksa koneksi dan coba lagi.</p>
      <button onClick={() => refetch()} className="text-sm font-[700] text-primary underline">Muat Ulang</button>
    </div>
  )
  if (!pdo) return <div className="text-muted text-sm">PDO tidak ditemukan.</div>

  const totalAmount  = allDetails.reduce((s, d) => s + (d.expense_item?.is_deduction ? -d.amount : d.amount), 0)
  const totalTransf  = allDetails.reduce((s, d) => s + (d.total_transferred ?? 0), 0)
  const totalReal    = allDetails.reduce((s, d) => s + (d.total_realized ?? 0), 0)
  const saldo        = totalTransf - totalReal

  const hasActiveFilter = !!itemSearch || filterAutoExternal || filterZeroAmount

  const matchesItemFilter = (d: PdoDetail): boolean => {
    if (filterAutoExternal && d.expense_item?.mode_input !== 'auto_external') return false
    if (filterZeroAmount && Number(d.amount) !== 0) return false
    if (itemSearch) {
      const q = itemSearch.toLowerCase()
      const haystack = `${d.expense_item?.code ?? ''} ${d.expense_item?.name ?? ''} ${d.description ?? ''}`.toLowerCase()
      if (!haystack.includes(q)) return false
    }
    return true
  }

  return (
    <div>
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
              <Button loading={submitMut.isPending} onClick={handleAjukan}>
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

        {(!!details?.length || suppGroups.length > 0) && (
          <div className="flex items-center gap-2 mb-3 flex-wrap">
            <div className="relative flex-1 min-w-[220px] max-w-[320px]">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
              <input
                className="input-base pl-9 w-full"
                placeholder="Cari kode/nama item..."
                value={itemSearch}
                onChange={(e) => setItemSearch(e.target.value)}
              />
            </div>
            <button
              type="button"
              className={`badge ${filterAutoExternal ? 'badge-approved' : 'badge-draft'} cursor-pointer`}
              onClick={() => setFilterAutoExternal((v) => !v)}
            >
              Auto External
            </button>
            <button
              type="button"
              className={`badge ${filterZeroAmount ? 'badge-approved' : 'badge-draft'} cursor-pointer`}
              onClick={() => setFilterZeroAmount((v) => !v)}
            >
              Jumlah = 0
            </button>
            {hasActiveFilter && (
              <button
                type="button"
                className="text-xs text-muted underline"
                onClick={() => { setItemSearch(''); setFilterAutoExternal(false); setFilterZeroAmount(false) }}
              >
                Reset filter
              </button>
            )}
          </div>
        )}

        {!details?.length && !suppGroups.length ? (
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
          for (const d of (details ?? []).filter(matchesItemFilter)) {
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

          const filteredSuppGroups = suppGroups
            .map((sg) => ({ ...sg, details: sg.details.filter(matchesItemFilter) }))
            .filter((sg) => sg.details.length > 0)

          return (
            <div className="overflow-auto max-h-[70vh]">
              <table className="w-full border-collapse" style={{ minWidth: 860 }}>
                <thead>
                  <tr>
                    {['Kategori / Item Biaya', 'Deskripsi', 'Vol', 'Satuan', 'Rate', 'Jumlah', 'Transfer', 'Realisasi', 'Saldo', ''].map((h) => (
                      <th key={h} className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] sticky top-0 z-10">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {groups.map((g) => {
                    const catCollapsed = collapsedCats.has(g.catKey)
                    const catTotal     = g.subs.reduce((s, sg) => s + sg.items.reduce((ss, d) => ss + (d.expense_item?.is_deduction ? -d.amount : d.amount), 0), 0)
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
                          const subTotal     = sg.items.reduce((s, d) => s + (d.expense_item?.is_deduction ? -d.amount : d.amount), 0)
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
                                        canUpload={false}
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

                {/* ── Tambahan section: merged items from PDO Tambahan ── */}
                {filteredSuppGroups.length > 0 && (
                  <tbody>
                    {/* Divider row */}
                    <tr>
                      <td colSpan={10} className="px-0 pt-2 pb-0">
                        <div className="flex items-center gap-2 px-3 py-2.5 bg-amber-50 border-t-2 border-amber-400">
                          <GitBranch className="w-4 h-4 text-amber-700 shrink-0" />
                          <span className="text-[12px] font-[850] text-amber-800 uppercase tracking-wide">Item dari PDO Tambahan</span>
                          <span className="ml-auto text-[11px] font-[700] text-amber-700 bg-amber-100 border border-amber-300 px-2 py-0.5 rounded-full whitespace-nowrap">
                            digabung setelah disetujui Direktur
                          </span>
                        </div>
                      </td>
                    </tr>

                    {filteredSuppGroups.map((sg) => (
                      <>
                        {/* Per-PDOT sub-header */}
                        <tr key={`supp-hdr-${sg.supplementary.id}`}>
                          <td colSpan={10} className="px-6 py-1.5 bg-amber-50 border-t border-amber-200">
                            <div className="flex items-center gap-2 text-[12px] text-amber-700">
                              <span className="font-[850]">{sg.supplementary.pdo_number}</span>
                              {sg.supplementary.merged_at && (
                                <span className="text-amber-500">· digabung {fmtDate(sg.supplementary.merged_at)}</span>
                              )}
                              <span className="ml-auto font-[700]">{fmt(sg.subtotal_amount)}</span>
                            </div>
                          </td>
                        </tr>

                        {/* Items: flat, but show cat · sub · code inline */}
                        {sg.details.map((d) => {
                          const sub = d.expense_item?.subcategory
                          const cat = sub?.category
                          const attachOpen = openAttachmentId === d.id
                          return (
                            <>
                              <tr key={d.id} className="border-t border-amber-100 bg-amber-50/30 hover:bg-amber-50/60">
                                <td className="pl-10 pr-3 py-2.5 text-sm font-bold">
                                  <div className="flex items-center gap-1.5 flex-wrap">
                                    {cat && (
                                      <span className="text-[10px] font-[700] px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200 whitespace-nowrap">
                                        {cat.code}
                                      </span>
                                    )}
                                    {sub && (
                                      <span className="text-[10px] font-[700] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 whitespace-nowrap">
                                        {sub.code}
                                      </span>
                                    )}
                                    {d.expense_item?.code && (
                                      <span className="text-[10px] font-[700] px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-100 whitespace-nowrap">
                                        {d.expense_item.code}
                                      </span>
                                    )}
                                    <span>{d.expense_item?.name ?? d.description ?? '—'}</span>
                                  </div>
                                  {(cat || sub) && (
                                    <div className="text-[10px] text-amber-600 mt-0.5 pl-0.5">
                                      {[cat?.name, sub?.name].filter(Boolean).join(' › ')}
                                    </div>
                                  )}
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
                                  key={`att-supp-${d.id}`}
                                  detailId={d.id}
                                  canUpload={false}
                                  colSpan={10}
                                />
                              )}
                            </>
                          )
                        })}
                      </>
                    ))}
                  </tbody>
                )}

                <tfoot>
                  <tr className="border-t-2 border-line bg-[#f7faf7]">
                    <td colSpan={5} className="px-3 py-2.5 text-[12px] font-[950] text-muted">
                      Total{suppGroups.length > 0 ? ' (Bulanan + Tambahan)' : ''}
                    </td>
                    <td className="px-3 py-2.5 font-[950]">{fmt(totalAmount)}</td>
                    <td className="px-3 py-2.5 font-[950]">{fmt(totalTransf)}</td>
                    <td className="px-3 py-2.5 font-[950]">{fmt(totalReal)}</td>
                    <td className="px-3 py-2.5 font-[950] text-green">{fmt(saldo)}</td>
                    <td />
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

      {/* Modal konfirmasi submit dengan item external belum/basi */}
      {submitWarningRows.length > 0 && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-xl shadow-xl p-6 max-w-md w-full mx-4">
            <h3 className="text-[17px] font-[850] text-ink mb-3">Konfirmasi Pengajuan</h3>
            <p className="text-sm text-muted mb-3">
              Item berikut belum atau perlu diperbarui datanya dari sistem eksternal:
            </p>
            <div className="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-4">
              <p className="text-sm font-[700] text-amber-800">
                Baris: {submitWarningRows.join(', ')}
              </p>
              <p className="text-xs text-amber-700 mt-1">
                Nilai yang digunakan adalah nilai terakhir yang tersimpan, yang mungkin belum mencerminkan data terkini.
              </p>
            </div>
            <p className="text-sm mb-5">Apakah Anda tetap ingin mengajukan PDO ini?</p>
            <div className="flex justify-end gap-3">
              <Button
                variant="secondary"
                onClick={() => setSubmitWarningRows([])}
              >
                Batal
              </Button>
              <Button
                loading={submitMut.isPending}
                onClick={() => {
                  setSubmitWarningRows([])
                  submitMut.mutate()
                }}
              >
                Tetap Ajukan
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
