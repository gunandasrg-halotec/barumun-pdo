import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useCategories, useSubcategories, useItems, useUsers } from '@/hooks/useMasterData'
import { useAuthStore } from '@/store/auth.store'
import { useToastStore } from '@/store/toast.store'
import { api } from '@/lib/api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { fmt } from '@/lib/format'
import { isAdmin, canEditMasterData } from '@/lib/auth'
import type { ApiResponse, PlantationUnit, RoleCode } from '@/types'
import { ChevronDown, ChevronRight } from 'lucide-react'

type Tab = 'hierarki' | 'items' | 'kebun' | 'users'

export function MasterDataPage() {
  const user     = useAuthStore((s) => s.user)
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const role     = user?.role.code as RoleCode | undefined
  const admin    = role ? isAdmin(role) : false
  const canEdit  = role ? canEditMasterData(role) : false

  const [tab, setTab]                   = useState<Tab>('hierarki')
  const [expandedCats, setExpandedCats] = useState<Set<string>>(new Set())
  const [expandedSubs, setExpandedSubs] = useState<Set<string>>(new Set())
  const [payrollMappings, setPayrollMappings] = useState<Record<string, string>>({})

  const { data: categories } = useCategories({ is_active: undefined })
  const { data: subcategories } = useSubcategories({ is_active: undefined })
  const { data: items } = useItems({ is_active: undefined })
  const { data: users } = useUsers()
  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units', { params: { exclude_ho: true } })
      return res.data.data
    },
    enabled: admin,
  })

  useEffect(() => {
    if (!units) return
    setPayrollMappings(Object.fromEntries(
      units.map((unit) => [unit.id, unit.payroll_estate_external_id ?? ''])
    ))
  }, [units])

  const updatePayrollMapping = useMutation({
    mutationFn: async (unit: PlantationUnit) => {
      const res = await api.put<ApiResponse<PlantationUnit>>(`/plantation-units/${unit.id}`, {
        payroll_estate_external_id: payrollMappings[unit.id] || null,
      })
      return res.data.data
    },
    onSuccess: () => {
      toast('Payroll Estate Mapping tersimpan')
      qc.invalidateQueries({ queryKey: ['plantation-units'] })
    },
    onError: () => toast('Gagal menyimpan Payroll Estate Mapping', 'error'),
  })

  const toggleCat = (id: string) => setExpandedCats((s) => {
    const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n
  })
  const toggleSub = (id: string) => setExpandedSubs((s) => {
    const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n
  })

  const subsByCat  = (catId: string) => subcategories?.filter((s) => s.category_id === catId) ?? []
  const itemsBySub = (subId: string) => items?.filter((i) => i.subcategory_id === subId) ?? []

  return (
    <div>
      {/* Hero */}
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Master Data</h2>
        </div>
        {canEdit && (
          <div className="flex gap-2">
            <Button onClick={() => navigate('/master/kategori/buat')}>+ Kategori</Button>
            <Button variant="secondary" onClick={() => navigate('/master/sub-kategori/buat')}>+ Sub-Kategori</Button>
            <Button variant="secondary" onClick={() => navigate('/master/item/buat')}>+ Item Biaya</Button>
          </div>
        )}
      </div>

      {/* Tabs */}
      <div className="flex gap-1 mb-5 border-b border-line">
        {(['hierarki', 'items', ...(admin ? ['kebun'] as const : []), 'users'] as Tab[]).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`px-4 py-2.5 text-sm font-bold capitalize border-b-2 transition-colors ${
              tab === t ? 'border-green text-green' : 'border-transparent text-muted hover:text-ink'
            }`}
          >
            {t === 'hierarki'
              ? 'Hierarki Biaya'
              : t === 'items'
                ? 'Item Biaya'
                : t === 'kebun'
                  ? 'Kebun'
                  : 'User & Role'}
          </button>
        ))}
      </div>

      {/* Tab: Hierarki */}
      {tab === 'hierarki' && (
        <div className="grid grid-cols-1 desk:grid-cols-[1.1fr_0.9fr] gap-4">
          <div className="overflow-auto border border-line rounded-drawer bg-white">
            <table className="w-full border-collapse">
              <thead>
                <tr>
                  {['Kategori / Sub / Item', 'Induk', 'Status', 'Aksi'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-[#526257] bg-[#f7faf7] sticky top-0">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {!categories?.length ? (
                  <tr><td colSpan={4} className="p-6"><EmptyState /></td></tr>
                ) : categories.map((cat) => (
                  <>
                    {/* Kategori row */}
                    <tr key={cat.id} className="group-row">
                      <td className="px-4 py-3 font-[950]">
                        <div className="flex items-center gap-2">
                          <button className="tree-toggle text-xs" onClick={() => toggleCat(cat.id)}>
                            {expandedCats.has(cat.id) ? <ChevronDown className="w-3 h-3" /> : <ChevronRight className="w-3 h-3" />}
                            Sub
                          </button>
                          {cat.code} — {cat.name}
                        </div>
                      </td>
                      <td className="px-4 py-3">—</td>
                      <td className="px-4 py-3">
                        <Badge variant={cat.is_active ? 'approved' : 'draft'}>
                          {cat.is_active ? 'Aktif' : 'Nonaktif'}
                        </Badge>
                      </td>
                      <td className="px-4 py-3">
                        {canEdit && (
                          <button className="text-sm text-green hover:underline font-bold"
                            onClick={() => navigate(`/master/kategori/${cat.id}/edit`)}>
                            Edit
                          </button>
                        )}
                      </td>
                    </tr>

                    {/* Sub-kategori rows */}
                    {expandedCats.has(cat.id) && subsByCat(cat.id).map((sub) => (
                      <>
                        <tr key={sub.id} className="subgroup-row">
                          <td className="px-4 py-3 pl-10 font-[900]">
                            <div className="flex items-center gap-2">
                              <button className="tree-toggle text-xs" onClick={() => toggleSub(sub.id)}>
                                {expandedSubs.has(sub.id) ? <ChevronDown className="w-3 h-3" /> : <ChevronRight className="w-3 h-3" />}
                                Item
                              </button>
                              {sub.code} — {sub.name}
                            </div>
                          </td>
                          <td className="px-4 py-3 text-sm">{cat.name}</td>
                          <td className="px-4 py-3">
                            <Badge variant={sub.is_active ? 'approved' : 'draft'}>
                              {sub.is_active ? 'Aktif' : 'Nonaktif'}
                            </Badge>
                          </td>
                          <td className="px-4 py-3">
                            {canEdit && (
                              <button className="text-sm text-green hover:underline font-bold"
                                onClick={() => navigate(`/master/sub-kategori/${sub.id}/edit`)}>
                                Edit
                              </button>
                            )}
                          </td>
                        </tr>

                        {/* Item rows */}
                        {expandedSubs.has(sub.id) && itemsBySub(sub.id).map((item) => (
                          <tr key={item.id} className="border-t border-line hover:bg-[#fbfdfb]">
                            <td className="px-4 py-2.5 pl-16 text-sm">{item.code} — {item.name}</td>
                            <td className="px-4 py-2.5 text-sm text-muted">{sub.name}</td>
                            <td className="px-4 py-2.5">
                              <Badge variant={item.is_active ? 'approved' : 'draft'}>
                                {item.is_active ? 'Aktif' : 'Nonaktif'}
                              </Badge>
                            </td>
                            <td className="px-4 py-2.5">
                              {canEdit && (
                                <button className="text-sm text-green hover:underline font-bold"
                                  onClick={() => navigate(`/master/item/${item.id}/edit`)}>
                                  Edit
                                </button>
                              )}
                            </td>
                          </tr>
                        ))}
                      </>
                    ))}
                  </>
                ))}
              </tbody>
            </table>
          </div>

          {/* Panduan alur kanan */}
          <div className="card sticky top-5 self-start">
            <h3 className="text-[17px] font-[850] mb-4">Panduan Alur</h3>
            <div className="flex flex-col gap-4">
              {[
                { num: 1, title: 'Tambah Kategori', desc: 'Kode unik, nama, urutan tampil', to: '/master/kategori/buat' },
                { num: 2, title: 'Tambah Sub-Kategori', desc: 'Pilih kategori induk, isi kode & nama', to: '/master/sub-kategori/buat' },
                { num: 3, title: 'Tambah Item Biaya', desc: 'Pilih sub-kategori, isi detail item', to: '/master/item/buat' },
              ].map((step) => (
                <div key={step.num} className="flex items-start gap-3">
                  <div className="w-7 h-7 rounded-full bg-mint text-green text-sm font-[950] flex items-center justify-center shrink-0">
                    {step.num}
                  </div>
                  <div className="flex-1">
                    <div className="font-[850] text-sm">{step.title}</div>
                    <div className="text-xs text-muted">{step.desc}</div>
                    {canEdit && (
                      <button
                        className="text-xs text-green hover:underline font-bold mt-1"
                        onClick={() => navigate(step.to)}
                      >
                        Buka Halaman →
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Tab: Item Biaya */}
      {tab === 'items' && (
        <div className="overflow-auto border border-line rounded-drawer bg-white">
          <table className="w-full border-collapse">
            <thead>
              <tr>
                {['Kategori', 'Sub-Kategori', 'Item Biaya', 'No Akun', 'Satuan', 'Tarif', 'Rutin', 'Mode Input', 'Status', 'Aksi'].map((h) => (
                  <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-[#526257] bg-[#f7faf7] sticky top-0">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {!items?.length ? (
                <tr><td colSpan={10} className="p-6"><EmptyState /></td></tr>
              ) : items.map((item) => {
                const sub = subcategories?.find((s) => s.id === item.subcategory_id)
                const cat = categories?.find((c) => c.id === sub?.category_id)
                return (
                  <tr key={item.id} className="border-t border-line hover:bg-[#fbfdfb]">
                    <td className="px-4 py-3 text-sm">{cat?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-sm">{sub?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-sm font-bold">{item.name}</td>
                    <td className="px-4 py-3 text-sm">{item.default_account_number ?? '—'}</td>
                    <td className="px-4 py-3 text-sm">{item.default_unit ?? '—'}</td>
                    <td className="px-4 py-3 text-sm">{fmt(item.default_rate)}</td>
                    <td className="px-4 py-3">
                      <Badge variant={item.is_routine ? 'approved' : 'draft'}>
                        {item.is_routine ? 'Ya' : 'Tidak'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={item.mode_input === 'auto_external' ? 'purple' : 'draft'}>
                        {item.mode_input === 'auto_external' ? 'Auto External' : 'Manual'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={item.is_active ? 'approved' : 'draft'}>
                        {item.is_active ? 'Aktif' : 'Nonaktif'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3">
                      {canEdit && (
                        <button className="text-sm text-green hover:underline font-bold"
                          onClick={() => navigate(`/master/item/${item.id}/edit`)}>
                          Edit
                        </button>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Tab: Kebun */}
      {tab === 'kebun' && admin && (
        <div className="overflow-auto border border-line rounded-drawer bg-white">
          <table className="w-full border-collapse">
            <thead>
              <tr>
                {['Kode', 'Kebun', 'Payroll Estate Mapping', 'Aksi'].map((h) => (
                  <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-[#526257] bg-[#f7faf7] sticky top-0">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {!units?.length ? (
                <tr><td colSpan={4} className="p-6"><EmptyState /></td></tr>
              ) : units.map((unit) => (
                <tr key={unit.id} className="border-t border-line hover:bg-[#fbfdfb]">
                  <td className="px-4 py-3 text-sm font-bold">{unit.code}</td>
                  <td className="px-4 py-3 text-sm">{unit.name}</td>
                  <td className="px-4 py-3">
                    <input
                      className="input-base max-w-xs"
                      value={payrollMappings[unit.id] ?? ''}
                      onChange={(event) => setPayrollMappings((current) => ({
                        ...current,
                        [unit.id]: event.target.value,
                      }))}
                      placeholder="Estate ID Payroll"
                    />
                  </td>
                  <td className="px-4 py-3">
                    <Button
                      size="sm"
                      loading={updatePayrollMapping.isPending}
                      onClick={() => updatePayrollMapping.mutate(unit)}
                    >
                      Simpan
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Tab: Users */}
      {tab === 'users' && (
        <div>
          {admin && (
            <div className="mb-4">
              <Button onClick={() => navigate('/master/user/buat')}>+ Tambah User</Button>
            </div>
          )}
          <div className="overflow-auto border border-line rounded-drawer bg-white">
            <table className="w-full border-collapse">
              <thead>
                <tr>
                  {['Nama', 'Email', 'Role', 'Unit Kebun', 'WhatsApp', 'Status', 'Aksi'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-[#526257] bg-[#f7faf7] sticky top-0">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {!users?.length ? (
                  <tr><td colSpan={7} className="p-6"><EmptyState /></td></tr>
                ) : users.map((u) => (
                  <tr key={u.id} className="border-t border-line hover:bg-[#fbfdfb]">
                    <td className="px-4 py-3 text-sm font-bold">{u.full_name}</td>
                    <td className="px-4 py-3 text-sm">{u.email}</td>
                    <td className="px-4 py-3 text-sm">{u.role.name}</td>
                    <td className="px-4 py-3 text-sm">{u.plantation_unit?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-sm">{u.whatsapp_number}</td>
                    <td className="px-4 py-3">
                      <Badge variant={u.is_active ? 'approved' : 'draft'}>
                        {u.is_active ? 'Aktif' : 'Nonaktif'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3">
                      {admin && (
                        <button className="text-sm text-green hover:underline font-bold"
                          onClick={() => navigate(`/master/user/${u.id}/edit`)}>
                          Edit
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
