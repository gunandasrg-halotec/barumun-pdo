import { NavLink } from 'react-router-dom'
import { useAuthStore } from '@/store/auth.store'
import { cn } from '@/lib/cn'
import {
  LayoutDashboard, List, Wallet, ArrowRightLeft,
  Database, BarChart2, Settings, FilePlus, BarChart3, ClipboardCheck, Truck,
} from 'lucide-react'
import type { RoleCode } from '@/types'

const NAV_ITEMS = [
  { to: '/dashboard',    icon: LayoutDashboard, label: 'Dashboard',       roles: null },
  { to: '/pdo',          icon: List,            label: 'Daftar PDO',      roles: null },
  { to: '/transfer',     icon: ArrowRightLeft,  label: 'Rencana Transfer Dana', roles: null },
  { to: '/perintah-transfer', icon: ClipboardCheck, label: 'Daftar Perintah Transfer',
    roles: ['MANAJER_KEUANGAN', 'STAFF_KEUANGAN', 'DIREKTUR_KEUANGAN', 'STAFF_PURCHASING'] as RoleCode[] },
  { to: '/rekapitulasi',       icon: BarChart3, label: 'Buku Kas Kebun',    roles: null },
  { to: '/realisasi',          icon: Wallet,   label: 'Realisasi Dana',    roles: null },
  { to: '/log-trip-kendaraan', icon: Truck,    label: 'Log Trip Kendaraan', roles: null },
  { to: '/pdo-tambahan', icon: FilePlus,        label: 'PDO Tambahan',    roles: null },
  { to: '/laporan',      icon: BarChart2,       label: 'Laporan',         roles: ['ADMIN', 'MANAJER_KEBUN', 'MANAJER_KEUANGAN', 'STAFF_KEUANGAN', 'DIREKTUR_KEUANGAN', 'STAFF_PURCHASING', 'DIREKTUR'] as RoleCode[] },
  { to: '/master',       icon: Database,        label: 'Master Data',     roles: null },
  { to: '/settings',     icon: Settings,        label: 'Pengaturan',      roles: ['ADMIN'] as RoleCode[] },
]

export function Sidebar() {
  const user = useAuthStore((s) => s.user)
  const role = user?.role.code as RoleCode | undefined

  const visibleItems = NAV_ITEMS.filter(
    (item) => !item.roles || (role && item.roles.includes(role))
  )

  return (
    <aside
      className="flex flex-col desk:h-screen desk:sticky desk:top-0 desk:overflow-hidden"
      style={{ background: '#0c3d2c', padding: '16px 14px' }}
    >
      {/* Brand */}
      <div className="flex items-center gap-3 mb-4 desk:mb-8">
        <div
          className="flex items-center justify-center text-white font-[900] text-sm rounded-[14px] shrink-0"
          style={{
            width: 44, height: 44,
            background: 'linear-gradient(135deg, #16a36d, #d4af37)',
          }}
        >
          PDO
        </div>
        <div>
          <div className="text-[17px] font-[800] text-[#d9f5e7] leading-tight">
            Dana Operasional Kebun
          </div>
          <div className="text-[12px] text-[#a8d4be]">PT Barumun Nauli</div>
        </div>
      </div>

      {/* Navigation — 2 cols on mobile, 1 col on desktop */}
      <nav className="grid grid-cols-2 gap-1 desk:flex desk:flex-col desk:flex-1">
        {visibleItems.map(({ to, icon: Icon, label }) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-2 desk:gap-3 px-3 desk:px-[13px] py-2.5 desk:py-3 rounded-[10px] text-[13px] desk:text-[14px] font-[750] transition-colors',
                'text-[#d9f5e7] no-underline',
                isActive
                  ? 'bg-[rgba(255,255,255,.13)]'
                  : 'hover:bg-[rgba(255,255,255,.08)]'
              )
            }
          >
            <Icon className="w-4 h-4 shrink-0" />
            <span className="truncate">{label}</span>
          </NavLink>
        ))}
      </nav>

      {/* User info — hidden on mobile to save space */}
      {user && (
        <div className="hidden desk:block mt-6 pt-4 border-t border-[rgba(255,255,255,.1)]">
          <div className="text-[12px] text-[#a8d4be]">{user.role.name}</div>
          <div className="text-[14px] font-[700] text-[#d9f5e7] truncate">{user.full_name}</div>
          {user.plantation_unit && (
            <div className="text-[12px] text-[#a8d4be]">{user.plantation_unit.name}</div>
          )}
        </div>
      )}
    </aside>
  )
}
