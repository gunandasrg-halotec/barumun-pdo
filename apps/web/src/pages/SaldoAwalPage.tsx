import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import type { ApiResponse } from '@/types'

interface UnitOpeningBalanceRow {
  plantation_unit_id: string
  unit_code: string
  unit_name: string
  amount: number
  as_of_date: string | null
  notes: string | null
  updated_at: string | null
}

const fmt = (n: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n)

export function SaldoAwalPage() {
  const qc    = useQueryClient()
  const toast = useToastStore((s) => s.push)

  const { data: rows } = useQuery({
    queryKey: ['unit-opening-balances'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<UnitOpeningBalanceRow[]>>('/unit-opening-balances')
      return res.data.data
    },
  })

  const [form, setForm] = useState<Record<string, { amount: string; as_of_date: string; notes: string }>>({})

  useEffect(() => {
    if (!rows) return
    setForm(Object.fromEntries(rows.map((r) => [
      r.plantation_unit_id,
      {
        amount:     String(r.amount ?? 0),
        as_of_date: r.as_of_date ?? '2026-06-30',
        notes:      r.notes ?? '',
      },
    ])))
  }, [rows])

  const save = useMutation({
    mutationFn: async (unitId: string) => {
      const f = form[unitId]
      return api.put(`/unit-opening-balances/${unitId}`, {
        amount:     Number(f.amount) || 0,
        as_of_date: f.as_of_date,
        notes:      f.notes || null,
      })
    },
    onSuccess: () => {
      toast('Saldo awal berhasil disimpan')
      qc.invalidateQueries({ queryKey: ['unit-opening-balances'] })
    },
    onError: () => toast('Gagal menyimpan saldo awal', 'error'),
  })

  return (
    <div className="p-6 space-y-4">
      <div>
        <h1 className="text-xl font-bold text-ink">Saldo Awal Kas Kebun</h1>
        <p className="text-sm text-muted mt-1">
          Saldo kas kebun akhir Juni 2026 (sebelum sistem PDO mulai dipakai 1 Juli 2026),
          jadi titik awal perhitungan saldo kumulatif di Buku Kas Harian, Rekap Buku Kas, dan Dashboard.
          Hanya berlaku untuk kantong Kas Kebun.
        </p>
      </div>

      <div className="card p-0 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-[#fbfdfb] border-b border-line">
            <tr>
              <th className="px-4 py-3 text-left text-[10px] font-[850] text-muted uppercase tracking-wider">Unit Kebun</th>
              <th className="px-4 py-3 text-left text-[10px] font-[850] text-muted uppercase tracking-wider">Saldo Awal</th>
              <th className="px-4 py-3 text-left text-[10px] font-[850] text-muted uppercase tracking-wider">Per Tanggal</th>
              <th className="px-4 py-3 text-left text-[10px] font-[850] text-muted uppercase tracking-wider">Catatan</th>
              <th className="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            {rows?.map((r) => {
              const f = form[r.plantation_unit_id]
              if (!f) return null
              return (
                <tr key={r.plantation_unit_id} className="border-t border-line">
                  <td className="px-4 py-3 font-semibold">{r.unit_code} — {r.unit_name}</td>
                  <td className="px-4 py-3">
                    <input
                      type="number"
                      className="input-base w-40"
                      value={f.amount}
                      onChange={(e) => setForm((prev) => ({ ...prev, [r.plantation_unit_id]: { ...prev[r.plantation_unit_id], amount: e.target.value } }))}
                    />
                    <div className="text-xs text-muted mt-0.5">{fmt(Number(f.amount) || 0)}</div>
                  </td>
                  <td className="px-4 py-3">
                    <input
                      type="date"
                      className="input-base"
                      value={f.as_of_date}
                      onChange={(e) => setForm((prev) => ({ ...prev, [r.plantation_unit_id]: { ...prev[r.plantation_unit_id], as_of_date: e.target.value } }))}
                    />
                  </td>
                  <td className="px-4 py-3">
                    <input
                      type="text"
                      className="input-base w-56"
                      placeholder="Opsional"
                      value={f.notes}
                      onChange={(e) => setForm((prev) => ({ ...prev, [r.plantation_unit_id]: { ...prev[r.plantation_unit_id], notes: e.target.value } }))}
                    />
                  </td>
                  <td className="px-4 py-3">
                    <Button size="sm" onClick={() => save.mutate(r.plantation_unit_id)} disabled={save.isPending}>
                      Simpan
                    </Button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}
