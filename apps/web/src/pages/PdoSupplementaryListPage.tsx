import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { getApiErrorMessage } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { fmt, fmtPeriode, fmtDate } from '@/lib/format'
import { isKerani } from '@/lib/auth'
import { Search } from 'lucide-react'
import { useToastStore } from '@/store/toast.store'
import type { PaginatedResponse, PdoSupplementaryHeader, RoleCode } from '@/types'

const STATUS_LABELS: Record<string, string> = {
  draft:               'Draft',
  submitted:           'Submitted',
  reviewed_asisten:    'Reviewed Asisten',
  in_review_manager:   'In Review Manager',
  in_review_direktur:  'In Review Direktur',
  final_merged:        'Merged ke PDO',
  rejected:            'Ditolak',
}

const STATUS_BADGE: Record<string, 'draft' | 'approved' | 'reject' | 'review' | 'purple'> = {
  draft:               'draft',
  submitted:           'review',
  reviewed_asisten:    'review',
  in_review_manager:   'review',
  in_review_direktur:  'review',
  final_merged:        'approved',
  rejected:            'reject',
}

const GROUPS = {
  proses: {
    title: 'Masih Proses',
    statuses: ['draft', 'submitted', 'reviewed_asisten', 'in_review_manager', 'in_review_direktur'],
  },
  merged: {
    title: 'Merged ke PDO',
    statuses: ['final_merged'],
  },
  ditolak: {
    title: 'Ditolak',
    statuses: ['rejected'],
  },
} as const

type GroupKey = keyof typeof GROUPS

function SupplementaryTable({
  groupKey,
  search,
  canCreate,
}: {
  groupKey: GroupKey
  search: string
  canCreate: boolean
}) {
  const navigate   = useNavigate()
  const toast      = useToastStore((s) => s.push)
  const queryClient = useQueryClient()
  const group = GROUPS[groupKey]
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const deleteMut = useMutation({
    mutationFn: (id: string) => api.delete(`/pdo-supplementary/${id}`),
    onSuccess: () => {
      toast('PDO Tambahan berhasil dihapus')
      queryClient.invalidateQueries({ queryKey: ['pdo-supplementary'] })
    },
    onError: (error) => toast(getApiErrorMessage(error), 'error'),
  })

  const handleDelete = (s: PdoSupplementaryHeader) => {
    if (!window.confirm(`Hapus ${s.pdo_number}? Tindakan ini tidak dapat dibatalkan.`)) return
    deleteMut.mutate(s.id)
  }

  const { data, isLoading } = useQuery({
    queryKey: ['pdo-supplementary', groupKey, page, perPage, search],
    queryFn: async () => {
      const res = await api.get<PaginatedResponse<PdoSupplementaryHeader>>('/pdo-supplementary', {
        params: {
          status:   group.statuses,
          page,
          per_page: perPage,
          ...(search ? { search } : {}),
        },
      })
      return res.data
    },
  })

  const list = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="mb-6">
      <h3 className="text-[15px] font-[850] mb-2">{group.title}</h3>
      <div className="overflow-auto border border-line rounded-drawer bg-white">
        <table className="w-full border-collapse" style={{ minWidth: 900 }}>
          <thead>
            <tr>
              {['Nomor PDO Tambahan', 'PDO Induk', 'Unit', 'Periode', 'Total', 'Status', 'Tgl Submit', 'Aksi'].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              Array.from({ length: 3 }).map((_, i) => (
                <tr key={i}>
                  {Array.from({ length: 8 }).map((__, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 bg-[#f0f4f0] rounded animate-pulse" />
                    </td>
                  ))}
                </tr>
              ))
            ) : !list.length ? (
              <tr><td colSpan={8} className="p-8"><EmptyState message={`Belum ada PDO Tambahan (${group.title}).`} /></td></tr>
            ) : list.map((s) => (
              <tr key={s.id} className="border-t border-line hover:bg-[#fbfdfb]">
                <td className="px-4 py-3 font-bold text-sm">{s.pdo_number}</td>
                <td className="px-4 py-3 text-sm">{s.parent_pdo?.pdo_number ?? s.parent_pdo_header_id}</td>
                <td className="px-4 py-3 text-sm">{s.plantation_unit?.name ?? s.plantation_unit_id}</td>
                <td className="px-4 py-3 text-sm">{fmtPeriode(s.period_month, s.period_year)}</td>
                <td className="px-4 py-3 text-sm">{fmt(0)}</td>
                <td className="px-4 py-3">
                  <Badge variant={STATUS_BADGE[s.status] ?? 'draft'}>
                    {STATUS_LABELS[s.status] ?? s.status}
                  </Badge>
                </td>
                <td className="px-4 py-3 text-sm">{s.submission_date ? fmtDate(s.submission_date) : '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex gap-2">
                    <button
                      className="text-sm font-bold text-green hover:underline"
                      onClick={() => navigate(`/pdo-tambahan/${s.id}`)}
                    >
                      Detail
                    </button>
                    {canCreate && s.status === 'draft' && (
                      <>
                        <button
                          className="text-sm font-bold text-green hover:underline"
                          onClick={() => navigate(`/pdo-tambahan/${s.id}/edit`)}
                        >
                          Edit
                        </button>
                        <button
                          className="text-sm font-bold text-red-600 hover:underline disabled:opacity-50"
                          disabled={deleteMut.isPending}
                          onClick={() => handleDelete(s)}
                        >
                          Hapus
                        </button>
                      </>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {meta && (
          <Pagination
            page={meta.current_page}
            lastPage={meta.last_page}
            total={meta.total}
            perPage={perPage}
            onPageChange={setPage}
            onPerPageChange={(n) => { setPerPage(n); setPage(1) }}
          />
        )}
      </div>
    </div>
  )
}

export function PdoSupplementaryListPage() {
  const navigate = useNavigate()
  const user     = useAuthStore((s) => s.user)
  const role     = user?.role.code as RoleCode | undefined
  const [search, setSearch] = useState('')
  const canCreate = role ? isKerani(role) : false

  return (
    <div>
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">PDO Tambahan</h2>
          <p className="text-muted text-sm mt-1">
            Pengajuan biaya tambahan di luar PDO Bulanan yang sudah disetujui.
          </p>
        </div>
        {canCreate && (
          <Button onClick={() => navigate('/pdo-tambahan/buat')}>+ Buat PDO Tambahan</Button>
        )}
      </div>

      <div className="flex items-center gap-3 mb-4">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input
            className="input-base pl-9 w-64"
            placeholder="Cari nomor PDO Tambahan..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>

      <SupplementaryTable groupKey="proses" search={search} canCreate={canCreate} />
      <SupplementaryTable groupKey="merged" search={search} canCreate={canCreate} />
      <SupplementaryTable groupKey="ditolak" search={search} canCreate={canCreate} />
    </div>
  )
}
