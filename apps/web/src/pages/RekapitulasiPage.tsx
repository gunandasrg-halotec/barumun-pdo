import { useState, useMemo, useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { useRecapData } from '@/hooks/useRecapData'
import { RecapTable } from '@/components/recap/RecapTable'
import { useAuthStore } from '@/store/auth.store'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate } from '@/lib/format'
import type { ApiResponse, PlantationUnit, PdoHeader, RealizationEntry, AuthUser } from '@/types'
import { Download, Search, Plus, Upload } from 'lucide-react'
import { DateRangePickerButton } from '@/components/ui/DateRangePickerButton'
import type { RecapResponse } from '@/types/recap'

const MONTHS = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]

const currentYear  = new Date().getFullYear()
const currentMonth = new Date().getMonth() + 1

const CROSS_UNIT_ROLES = ['ADMIN', 'MANAJER_KEBUN', 'MANAJER_KEUANGAN', 'STAFF_KEUANGAN', 'DIREKTUR_KEUANGAN', 'STAFF_PURCHASING']

function isPribadiVendorRole(user: AuthUser | undefined): boolean {
  return user?.role?.code === 'STAFF_PURCHASING' || user?.role?.code === 'MANAJER_KEUANGAN'
}

// ── Form schema Input Realisasi ──────────────────────────────────────────────
const realizationSchema = z.object({
  pdo_detail_id:    z.string().uuid('Pilih item biaya'),
  transaction_date: z.string().min(1, 'Tanggal wajib diisi'),
  amount:           z.coerce.number().min(1, 'Jumlah harus > 0'),
  payment_method:   z.enum(['tunai', 'transfer']),
  funding_source:   z.enum(['kas_kebun', 'rekening_kebun', 'rekening_utama']),
  proof_number:     z.string().min(1, 'No. referensi wajib diisi'),
  explanation:      z.string().nullable().optional(),
})
type RealizationForm = z.infer<typeof realizationSchema>

interface RealizationAvailableItem {
  pdo_detail_id:  string
  expense_item:   { id: string; code: string; name: string } | null
  description:    string
  bucket:         number
  realized_group: number
  saldo:          number
}

interface RealizationAvailableResponse {
  items:             RealizationAvailableItem[]
  remaining_kantong: number
  total_kantong:     number
}

const FUNDING_LABEL: Record<string, string> = {
  kas_kebun: 'Kas Kebun', rekening_kebun: 'Rekening Kebun', rekening_utama: 'Rekening Utama',
}
const PAYMENT_LABEL: Record<string, string> = {
  tunai: 'Tunai', transfer: 'Transfer Bank',
}

export function RekapitulasiPage() {
  const user    = useAuthStore((s) => s.user)
  const role    = user?.role?.code ?? ''
  const toast   = useToastStore((s) => s.push)
  const qc      = useQueryClient()

  const isCrossUnit = CROSS_UNIT_ROLES.includes(role)

  // ── Period / unit filter state ───────────────────────────────────────────
  const [year,              setYear]              = useState(currentYear)
  const [month,             setMonth]             = useState(currentMonth)
  const [unitId,            setUnitId]            = useState(user?.plantation_unit?.id ?? '')
  const [categoryId]                              = useState('')
  const [realizationFilter, setRealizationFilter] = useState<'all' | 'has' | 'no'>('all')
  const [search,            setSearch]            = useState('')
  const [startDate,         setStartDate]         = useState('')
  const [endDate,           setEndDate]           = useState('')

  // ── Period boundaries (for date range validation) ────────────────────────
  const periodMin = `${year}-${String(month).padStart(2, '0')}-01`
  const periodMax = (() => {
    const d = new Date(year, month, 0) // last day of month
    return `${year}-${String(month).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  })()

  // ── Modal state ──────────────────────────────────────────────────────────
  const [inputOpen, setInputOpen]         = useState(false)
  const [apiError,  setApiError]          = useState<string | null>(null)
  const [uploadId,  setUploadId]          = useState<string | null>(null)
  const [file,      setFile]              = useState<File | null>(null)
  // realisasi detail modal
  const [detailItem, setDetailItem]       = useState<{ pdoDetailId: string; itemName: string } | null>(null)
  // realisasi aggregate drill-down modal (klik cell "Realisasi" di KPI header)
  const [aggregateGroup, setAggregateGroup] = useState<'kebun' | 'pribadi' | null>(null)

  // ── Plantation units (cross-unit roles only) ─────────────────────────────
  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
    enabled: isCrossUnit,
  })

  const resolvedUnitId = isCrossUnit ? unitId : (user?.plantation_unit?.id ?? '')

  // Reset date filters when period changes
  useEffect(() => {
    setStartDate('')
    setEndDate('')
  }, [year, month])

  // Only pass dates that exist (validation handled inside DateRangePickerButton — onChange only fires on valid input)
  const validStartDate = startDate || undefined
  const validEndDate   = endDate   || undefined

  // ── Recap data ───────────────────────────────────────────────────────────
  const { data: recap, isFetching, isError } = useRecapData(
    {
      period_year:  year,
      period_month: month,
      unit_id:      resolvedUnitId || undefined,
      category_id:  categoryId    || undefined,
      start_date:   validStartDate,
      end_date:     validEndDate,
    },
    !!resolvedUnitId,
  )

  // ── Active PDO for current period+unit (for input form) ──────────────────
  const { data: pdoList } = useQuery({
    queryKey: ['pdo-final-for-realisasi', year, month, resolvedUnitId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoHeader[]>>('/pdo', {
        params: { status: 'final', period_year: year, period_month: month, plantation_unit_id: resolvedUnitId },
      })
      return res.data.data
    },
    enabled: !!resolvedUnitId,
  })
  const activePdo = pdoList?.[0]

  // ── Available items for active PDO ───────────────────────────────────────
  const { data: availableData } = useQuery({
    queryKey: ['realizations-available', activePdo?.id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<RealizationAvailableResponse>>(
        `/pdo/${activePdo!.id}/realizations/available`,
      )
      return res.data.data
    },
    enabled: !!activePdo?.id && inputOpen,
  })
  const availableItems     = availableData?.items ?? []
  const remainingKantong   = availableData?.remaining_kantong ?? null
  const totalKantong       = availableData?.total_kantong ?? null

  // ── Input Realisasi form ─────────────────────────────────────────────────
  const { register, handleSubmit, watch, reset, setValue, formState: { errors } } = useForm<RealizationForm>({
    resolver: zodResolver(realizationSchema),
    defaultValues: {
      transaction_date: new Date().toISOString().split('T')[0],
      payment_method:   isPribadiVendorRole(user ?? undefined) ? 'transfer' : 'tunai',
      funding_source:   isPribadiVendorRole(user ?? undefined) ? 'rekening_utama' : 'kas_kebun',
    },
  })

  const selectedPaymentMethod = watch('payment_method')

  useEffect(() => {
    if (isPribadiVendorRole(user ?? undefined)) {
      setValue('funding_source', 'rekening_utama')
      return
    }
    setValue('funding_source', selectedPaymentMethod === 'transfer' ? 'rekening_kebun' : 'kas_kebun')
  }, [user, selectedPaymentMethod, setValue])

  const availablePaymentMethods = isPribadiVendorRole(user ?? undefined) ? ['transfer'] : ['tunai', 'transfer']

  const saveRealization = useMutation({
    mutationFn: (data: RealizationForm) =>
      api.post<ApiResponse<RealizationEntry>>('/realization-entries', data),
    onSuccess: (res) => {
      setApiError(null)
      toast('Realisasi berhasil dicatat')
      qc.invalidateQueries({ queryKey: ['recap'] })
      qc.invalidateQueries({ queryKey: ['realizations'] })
      const entry = res.data.data
      setInputOpen(false)
      reset()
      setUploadId(entry.id)
    },
    onError: (error: any) => {
      const message = error?.response?.data?.error?.message || error?.response?.data?.message || error?.message || 'Gagal menyimpan realisasi'
      setApiError(message)
      toast(message, 'error')
    },
  })

  const uploadBukti = useMutation({
    mutationFn: () => {
      if (!file || !uploadId) throw new Error('File atau ID tidak tersedia')
      const fd = new FormData()
      fd.append('file', file)
      return api.post(`/realization-entries/${uploadId}/attachments`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
    onSuccess: () => {
      toast('Bukti berhasil diupload')
      qc.invalidateQueries({ queryKey: ['recap'] })
      qc.invalidateQueries({ queryKey: ['realizations'] })
      setUploadId(null)
      setFile(null)
    },
    onError: () => toast('Gagal upload bukti', 'error'),
  })

  // ── Realisasi detail entries (click on item cell) ────────────────────────
  const { data: detailEntries, isFetching: detailFetching } = useQuery({
    queryKey: ['realisasi-detail', detailItem?.pdoDetailId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<RealizationEntry[]>>('/realization-entries', {
        params: { pdo_detail_id: detailItem!.pdoDetailId },
      })
      return res.data.data
    },
    enabled: !!detailItem,
  })

  // ── Realisasi aggregate entries (click on KPI header "Realisasi" cell) ───
  const AGGREGATE_FUNDING_SOURCES: Record<'kebun' | 'pribadi', string[]> = {
    kebun:   ['kas_kebun', 'rekening_kebun'],
    pribadi: ['rekening_utama'],
  }
  const { data: aggregateEntries, isFetching: aggregateFetching } = useQuery({
    queryKey: ['realisasi-aggregate', aggregateGroup, resolvedUnitId, year, month, validStartDate, validEndDate],
    queryFn: async () => {
      const params = new URLSearchParams()
      params.set('unit_id', resolvedUnitId)
      params.set('period_year', String(year))
      params.set('period_month', String(month))
      if (validStartDate) params.set('start_date', validStartDate)
      if (validEndDate)   params.set('end_date', validEndDate)
      AGGREGATE_FUNDING_SOURCES[aggregateGroup!].forEach((fs) => params.append('funding_source[]', fs))
      const res = await api.get<ApiResponse<RealizationEntry[]>>('/realization-entries', { params })
      return res.data.data
    },
    enabled: !!aggregateGroup && !!resolvedUnitId,
  })

  // ── Filtered recap ───────────────────────────────────────────────────────
  const filteredRecap = useMemo((): RecapResponse | null => {
    if (!recap) return null
    const q = search.trim().toLowerCase()

    const categories = recap.categories
      .map((cat) => {
        const subcategories = cat.subcategories
          .map((sub) => {
            const items = sub.items.filter((item) => {
              const matchSearch = !q || item.item_name.toLowerCase().includes(q) || item.item_code.toLowerCase().includes(q)
              // When date filter is active, only show rows that have realization in that range
              const hasDateFilter = !!(startDate || endDate)
              const matchFilter =
                (hasDateFilter && item.total_realization > 0) ||
                (!hasDateFilter && (
                  realizationFilter === 'all' ||
                  (realizationFilter === 'has' && item.total_realization > 0) ||
                  (realizationFilter === 'no'  && item.total_realization === 0)
                ))
              return matchSearch && matchFilter
            })
            return { ...sub, items }
          })
          .filter((sub) => sub.items.length > 0)
        return { ...cat, subcategories }
      })
      .filter((cat) => cat.subcategories.length > 0)

    return { ...recap, categories }
  }, [recap, search, realizationFilter])

  const [excelLoading, setExcelLoading] = useState(false)

  const downloadExcel = async () => {
    if (!resolvedUnitId) return
    setExcelLoading(true)
    try {
      const params: Record<string, string | number> = { period_year: year, period_month: month, unit_id: resolvedUnitId }
      if (categoryId)      params.category_id = categoryId
      if (validStartDate)  params.start_date  = validStartDate
      if (validEndDate)    params.end_date    = validEndDate
      const res = await api.get('/reports/recap/export', { params, responseType: 'blob' })
      const url = URL.createObjectURL(res.data)
      const a   = document.createElement('a')
      a.href    = url
      a.download = `BukuKasKebun_${year}_${month}${resolvedUnitId ? '' : ''}.xlsx`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      toast('Gagal mengunduh Excel', 'error')
    } finally {
      setExcelLoading(false)
    }
  }

  return (
    <div>
      {/* Header */}
      <div className="flex items-start justify-between mb-5">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Buku Kas Kebun</h2>
          <p className="text-muted text-sm mt-1">
            {recap ? `${recap.period_label}${recap.unit ? ` · ${recap.unit.name}` : ''}` : 'Pilih periode dan unit untuk menampilkan data.'}
          </p>
        </div>
        <div className="flex gap-2">
          {activePdo?.status === 'final' && (
            <Button onClick={() => setInputOpen(true)}>
              <Plus className="w-4 h-4" /> Input Realisasi
            </Button>
          )}
          {resolvedUnitId && (
            <Button variant="secondary" size="sm" loading={excelLoading} onClick={downloadExcel}>
              <Download className="w-4 h-4" /> Excel
            </Button>
          )}
        </div>
      </div>

      {/* Filter Bar */}
      <div className="card mb-5 flex flex-wrap gap-3 items-end">
        <div>
          <label className="label">Tahun</label>
          <select className="input-base" value={year} onChange={(e) => setYear(+e.target.value)}>
            {Array.from({ length: 6 }, (_, i) => currentYear - 2 + i).map((y) => (
              <option key={y} value={y}>{y}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="label">Bulan</label>
          <select className="input-base" value={month} onChange={(e) => setMonth(+e.target.value)}>
            {MONTHS.map((m, i) => (
              <option key={i + 1} value={i + 1}>{m}</option>
            ))}
          </select>
        </div>

        {isCrossUnit && (
          <div>
            <label className="label">Unit Kebun</label>
            <select className="input-base" value={unitId} onChange={(e) => setUnitId(e.target.value)}>
              <option value="">— Pilih Unit —</option>
              {units?.map((u) => (
                <option key={u.id} value={u.id}>{u.code} — {u.name}</option>
              ))}
            </select>
          </div>
        )}

        <div>
          <label className="label">Realisasi</label>
          <select className="input-base" value={realizationFilter} onChange={(e) => setRealizationFilter(e.target.value as 'all' | 'has' | 'no')}>
            <option value="all">Semua</option>
            <option value="has">Sudah ada realisasi</option>
            <option value="no">Belum ada realisasi</option>
          </select>
        </div>

        <div className="flex items-end">
          <DateRangePickerButton
            startDate={startDate}
            endDate={endDate}
            min={periodMin}
            max={periodMax}
            onChange={(s, e) => { setStartDate(s); setEndDate(e) }}
          />
        </div>

        <div className="flex-1 min-w-[200px]">
          <label className="label">Cari Item Biaya</label>
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted pointer-events-none" />
            <input
              type="text"
              className="input-base pl-8"
              placeholder="Nama atau kode item..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>
      </div>

      {/* Content */}
      {isFetching ? (
        <div className="card space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="h-8 bg-[#f0f4f0] rounded animate-pulse" />
          ))}
        </div>
      ) : isError ? (
        <div className="card text-sm text-red-600">Gagal memuat data. Coba lagi.</div>
      ) : !recap || recap.categories.length === 0 ? (
        <EmptyState message="Tidak ada data untuk periode dan unit ini." />
      ) : (
        <div className="card p-0 overflow-hidden">
          {/* Summary KPI */}
          <div className="border-b border-line">
            {/* Row 1: Total Pengajuan + Total Dana Di Transfer */}
            <div className="grid grid-cols-2 border-b border-line">
              {[
                { label: 'Total Pengajuan',        value: recap.grand_total_amount },
                { label: 'Total Dana Di Transfer',  value: recap.grand_total_transfer },
              ].map((k) => (
                <div key={k.label} className="p-4 text-center border-r border-line last:border-r-0">
                  <div className="text-[10px] font-[850] text-muted uppercase tracking-wider mb-1">{k.label}</div>
                  <div className="text-[17px] font-[950] text-ink">
                    {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(k.value)}
                  </div>
                </div>
              ))}
            </div>
            {/* Row 2: Kas Kebun (3 cols) + Pribadi/Vendor (3 cols) */}
            <div className="grid grid-cols-6">
              {/* Kas Kebun */}
              {[
                { label: 'Transfer',   value: recap.transfer_kebun,   group: 'Kas Kebun', clickGroup: null },
                { label: 'Realisasi',  value: recap.realisasi_kebun,  group: 'Kas Kebun', clickGroup: 'kebun' as const },
                { label: 'Saldo',      value: recap.saldo_kebun,      group: 'Kas Kebun', clickGroup: null },
              ].map((k, i) => (
                <div
                  key={`kebun-${i}`}
                  className={`p-3 text-center border-r border-line border-t-2 border-t-[#1D9E75] ${k.clickGroup ? 'cursor-pointer hover:bg-[#f7faf7] transition-colors' : ''}`}
                  onClick={k.clickGroup ? () => setAggregateGroup(k.clickGroup) : undefined}
                  title={k.clickGroup ? 'Klik untuk lihat riwayat realisasi Kas Kebun' : undefined}
                >
                  <div className="text-[9px] font-[700] text-[#0F6E56] uppercase tracking-wider mb-0.5">{k.group}</div>
                  <div className="text-[10px] font-[850] text-muted uppercase tracking-wider mb-1">{k.label}</div>
                  <div className={`text-[14px] font-[950] ${k.value < 0 ? 'text-red-600' : 'text-[#0F6E56]'} ${k.clickGroup ? 'underline decoration-dotted underline-offset-2' : ''}`}>
                    {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(k.value)}
                  </div>
                </div>
              ))}
              {/* Pribadi / Vendor */}
              {[
                { label: 'Transfer',   value: recap.transfer_pribadi,   group: 'Pribadi / Vendor', clickGroup: null },
                { label: 'Realisasi',  value: recap.realisasi_pribadi,  group: 'Pribadi / Vendor', clickGroup: 'pribadi' as const },
                { label: 'Saldo',      value: recap.saldo_pribadi,      group: 'Pribadi / Vendor', clickGroup: null },
              ].map((k, i) => (
                <div
                  key={`pribadi-${i}`}
                  className={`p-3 text-center border-r border-line last:border-r-0 border-t-2 border-t-[#185FA5] ${k.clickGroup ? 'cursor-pointer hover:bg-[#f7faf7] transition-colors' : ''}`}
                  onClick={k.clickGroup ? () => setAggregateGroup(k.clickGroup) : undefined}
                  title={k.clickGroup ? 'Klik untuk lihat riwayat realisasi Pribadi/Vendor' : undefined}
                >
                  <div className="text-[9px] font-[700] text-[#185FA5] uppercase tracking-wider mb-0.5">{k.group}</div>
                  <div className="text-[10px] font-[850] text-muted uppercase tracking-wider mb-1">{k.label}</div>
                  <div className={`text-[14px] font-[950] ${k.value < 0 ? 'text-red-600' : 'text-[#185FA5]'} ${k.clickGroup ? 'underline decoration-dotted underline-offset-2' : ''}`}>
                    {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(k.value)}
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Hierarchical table */}
          {filteredRecap && filteredRecap.categories.length > 0
            ? <RecapTable
                data={filteredRecap}
                onRealizationClick={(pdoDetailId, itemName) => setDetailItem({ pdoDetailId, itemName })}
              />
            : <div className="p-8 text-center text-sm text-muted">Tidak ada item yang cocok dengan filter.</div>
          }
        </div>
      )}

      {/* ── Modal Input Realisasi ───────────────────────────────────────────── */}
      <Modal open={inputOpen} onClose={() => { setInputOpen(false); reset(); setApiError(null) }} title="Input Realisasi Biaya">
        {!activePdo ? (
          <p className="text-sm text-muted">Tidak ada PDO aktif (status Final) untuk periode dan unit ini.</p>
        ) : (
          <form onSubmit={handleSubmit((d) => saveRealization.mutate(d))} className="flex flex-col gap-4">
            <div className="p-3 bg-[#f7faf7] border border-line rounded text-sm">
              PDO: <span className="font-bold">{activePdo.pdo_number}</span>
            </div>

            {/* Sisa kantong — prominent, shown as soon as data is loaded */}
            {remainingKantong !== null && (
              <div className={`rounded-lg px-4 py-3 border ${remainingKantong <= 0 ? 'bg-red-50 border-red-300' : 'bg-amber-50 border-amber-300'}`}>
                <p className={`text-xs font-bold uppercase tracking-wider mb-0.5 ${remainingKantong <= 0 ? 'text-red-500' : 'text-amber-600'}`}>
                  Sisa Dana
                </p>
                <p className={`text-2xl font-black ${remainingKantong <= 0 ? 'text-red-700' : 'text-amber-800'}`}>
                  {fmt(remainingKantong)}
                </p>
                {totalKantong !== null && (
                  <p className={`text-xs mt-0.5 ${remainingKantong <= 0 ? 'text-red-500' : 'text-amber-600'}`}>
                    dari total dana {fmt(totalKantong)}
                  </p>
                )}
                {remainingKantong <= 0 && (
                  <p className="text-xs font-bold text-red-600 mt-1">Kantong habis — tidak bisa mencatat realisasi baru.</p>
                )}
              </div>
            )}

            <div>
              <label className="label">Item Biaya</label>
              <select {...register('pdo_detail_id')} className="input-base">
                <option value="">Pilih item...</option>
                {availableItems.map((d) => (
                  <option key={d.pdo_detail_id} value={d.pdo_detail_id}>
                    {d.expense_item?.name ?? d.description} — Saldo: {fmt(d.saldo)}
                  </option>
                ))}
              </select>
              {errors.pdo_detail_id && <p className="field-error">{errors.pdo_detail_id.message}</p>}
              {availableItems.length === 0 && (
                <p className="text-xs text-muted mt-1">Tidak ada item yang bisa Anda realisasi untuk PDO ini.</p>
              )}
            </div>

            {apiError && (
              <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
                <p>{apiError}</p>
              </div>
            )}

            <div className="grid grid-cols-1 desk:grid-cols-2 gap-3">
              <div>
                <label className="label">Tanggal Transaksi</label>
                <input type="date" {...register('transaction_date')} className="input-base" />
              </div>
              <div>
                <label className="label">Jumlah (Rp)</label>
                <input type="number" {...register('amount')} className="input-base" />
                {errors.amount && <p className="field-error">{errors.amount.message}</p>}
              </div>
            </div>

            <div className="grid grid-cols-1 desk:grid-cols-2 gap-3">
              <div>
                <label className="label">Metode Pembayaran</label>
                <select {...register('payment_method')} className="input-base">
                  {availablePaymentMethods.includes('tunai') && <option value="tunai">Tunai</option>}
                  {availablePaymentMethods.includes('transfer') && <option value="transfer">Transfer Bank</option>}
                </select>
              </div>
              <div>
                <label className="label">Sumber Dana</label>
                <select {...register('funding_source')} className="input-base bg-[#f7faf7]" disabled>
                  <option value="kas_kebun">{FUNDING_LABEL.kas_kebun}</option>
                  <option value="rekening_kebun">{FUNDING_LABEL.rekening_kebun}</option>
                  <option value="rekening_utama">{FUNDING_LABEL.rekening_utama}</option>
                </select>
              </div>
            </div>

            <div>
              <label className="label">No. Referensi / Kuitansi</label>
              <input {...register('proof_number')} className="input-base" placeholder="KWT/2026/001" />
              {errors.proof_number && <p className="field-error">{errors.proof_number.message}</p>}
            </div>

            <div>
              <label className="label">Penjelasan (opsional)</label>
              <input {...register('explanation')} className="input-base" />
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="secondary" onClick={() => { setInputOpen(false); reset() }}>Batal</Button>
              <Button type="submit" loading={saveRealization.isPending}>Simpan Realisasi</Button>
            </div>
          </form>
        )}
      </Modal>

      {/* ── Modal Upload Bukti ──────────────────────────────────────────────── */}
      <Modal open={!!uploadId} onClose={() => { setUploadId(null); setFile(null) }} title="Upload Bukti Pembayaran">
        <p className="text-sm text-muted mb-4">
          Upload foto/scan kuitansi, bon, atau bukti transfer. Format: JPG, PNG, PDF. Maks 10 MB.
        </p>
        <div
          className="border-2 border-dashed border-line rounded-drawer p-8 text-center cursor-pointer hover:border-green transition-colors"
          onClick={() => document.getElementById('file-upload-buku-kas')?.click()}
        >
          <Upload className="w-8 h-8 text-muted mx-auto mb-2" />
          <p className="text-sm text-muted">{file ? file.name : 'Klik atau drag file ke sini'}</p>
          <input
            id="file-upload-buku-kas"
            type="file"
            accept=".jpg,.jpeg,.png,.pdf"
            className="hidden"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
        </div>
        <div className="flex justify-end gap-2 mt-5">
          <Button variant="secondary" onClick={() => { setUploadId(null); setFile(null) }}>Tutup</Button>
          <Button loading={uploadBukti.isPending} disabled={!file} onClick={() => uploadBukti.mutate()}>
            Upload
          </Button>
        </div>
      </Modal>

      {/* ── Modal Detail Realisasi (per item) ─────────────────────────────────── */}
      <Modal
        open={!!detailItem}
        onClose={() => setDetailItem(null)}
        title={`Detail Realisasi — ${detailItem?.itemName ?? ''}`}
        width="w-[820px]"
        className="!max-h-none"
      >
        {detailFetching ? (
          <div className="space-y-3 py-2">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-6 bg-[#f0f4f0] rounded animate-pulse" />
            ))}
          </div>
        ) : !detailEntries?.length ? (
          <EmptyState message="Belum ada data realisasi." />
        ) : (
          <div>
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr>
                  {['No. Ref', 'Tanggal', 'Jumlah', 'Metode', 'Sumber Dana', 'Dicatat Oleh'].map((h) => (
                    <th key={h} className="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider bg-[#f7faf7] border border-line">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {detailEntries.map((r) => (
                  <tr key={r.id} className="border-t border-line hover:bg-[#fbfdfb]">
                    <td className="px-3 py-2 font-bold">{r.proof_number}</td>
                    <td className="px-3 py-2">{fmtDate(r.transaction_date)}</td>
                    <td className="px-3 py-2 font-bold text-right">{fmt(r.amount)}</td>
                    <td className="px-3 py-2">{PAYMENT_LABEL[r.payment_method]}</td>
                    <td className="px-3 py-2">{FUNDING_LABEL[r.funding_source] ?? r.funding_source}</td>
                    <td className="px-3 py-2">{r.recorder?.full_name ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="flex justify-end mt-4">
          <Button variant="secondary" onClick={() => setDetailItem(null)}>Tutup</Button>
        </div>
      </Modal>

      {/* ── Modal Detail Realisasi (aggregate — klik cell KPI header) ─────────── */}
      <Modal
        open={!!aggregateGroup}
        onClose={() => setAggregateGroup(null)}
        title={`Realisasi ${aggregateGroup === 'kebun' ? 'Kas Kebun' : 'Pribadi / Vendor'}`}
        width="w-[900px]"
        className="!max-h-none"
      >
        {aggregateFetching ? (
          <div className="space-y-3 py-2">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="h-6 bg-[#f0f4f0] rounded animate-pulse" />
            ))}
          </div>
        ) : !aggregateEntries?.length ? (
          <EmptyState message="Belum ada data realisasi." />
        ) : (
          <div>
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr>
                  {['No. Ref', 'Tanggal', 'Item Biaya', 'Jumlah', 'Metode', 'Sumber Dana', 'Dicatat Oleh'].map((h) => (
                    <th key={h} className="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider bg-[#f7faf7] border border-line">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {aggregateEntries.map((r) => (
                  <tr key={r.id} className="border-t border-line hover:bg-[#fbfdfb]">
                    <td className="px-3 py-2 font-bold">{r.proof_number}</td>
                    <td className="px-3 py-2">{fmtDate(r.transaction_date)}</td>
                    <td className="px-3 py-2">{r.pdo_detail?.expense_item?.name ?? '—'}</td>
                    <td className="px-3 py-2 font-bold text-right">{fmt(r.amount)}</td>
                    <td className="px-3 py-2">{PAYMENT_LABEL[r.payment_method]}</td>
                    <td className="px-3 py-2">{FUNDING_LABEL[r.funding_source] ?? r.funding_source}</td>
                    <td className="px-3 py-2">{r.recorder?.full_name ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-line font-bold">
                  <td className="px-3 py-2" colSpan={3}>Total</td>
                  <td className="px-3 py-2 text-right">{fmt(aggregateEntries.reduce((s, r) => s + r.amount, 0))}</td>
                  <td colSpan={3} />
                </tr>
              </tfoot>
            </table>
          </div>
        )}
        <div className="flex justify-end mt-4">
          <Button variant="secondary" onClick={() => setAggregateGroup(null)}>Tutup</Button>
        </div>
      </Modal>
    </div>
  )
}
