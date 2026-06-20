import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { fmt, fmtPeriode, fmtDate } from '@/lib/format'
import { isKerani } from '@/lib/auth'
import { Search } from 'lucide-react'
import type { ApiResponse, PdoSupplementaryHeader, RoleCode } from '@/types'

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

export function PdoSupplementaryListPage() {
  const navigate = useNavigate()
  const user     = useAuthStore((s) => s.user)
  const role     = user?.role.code as RoleCode | undefined
  const [search, setSearch] = useState('')

  const { data: list, isLoading } = useQuery({
    queryKey: ['pdo-supplementary', search],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoSupplementaryHeader[]>>('/pdo-supplementary', {
        params: search ? { search } : {},
      })
      return res.data.data
    },
  })

  return (
    <div>
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">PDO Tambahan</h2>
          <p className="text-muted text-sm mt-1">
            Pengajuan biaya tambahan di luar PDO Bulanan yang sudah disetujui.
          </p>
        </div>
        {role && isKerani(role) && (
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
              Array.from({ length: 4 }).map((_, i) => (
                <tr key={i}>
                  {Array.from({ length: 8 }).map((__, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 bg-[#f0f4f0] rounded animate-pulse" />
                    </td>
                  ))}
                </tr>
              ))
            ) : !list?.length ? (
              <tr><td colSpan={8} className="p-8"><EmptyState message='Belum ada PDO Tambahan.' /></td></tr>
            ) : list.map((s) => (
              <tr key={s.id} className="border-t border-line hover:bg-[#fbfdfb]">
                <td className="px-4 py-3 font-bold text-sm">{s.pdo_number}</td>
                <td className="px-4 py-3 text-sm">{s.parent_pdo_header_id}</td>
                <td className="px-4 py-3 text-sm">{s.plantation_unit_id}</td>
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
                    {role && isKerani(role) && s.status === 'draft' && (
                      <button
                        className="text-sm font-bold text-green hover:underline"
                        onClick={() => navigate(`/pdo-tambahan/${s.id}/edit`)}
                      >
                        Edit
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
