import { NavLink } from 'react-router-dom'
import { useAuthStore } from '@/store/auth.store'
import { cn } from '@/lib/cn'
import {
  LayoutDashboard, List, Wallet, ArrowRightLeft,
  Database, BarChart2, Settings, FilePlus, BarChart3,
} from 'lucide-react'
import type { RoleCode } from '@/types'

const NAV_ITEMS = [
  { to: '/dashboard',    icon: LayoutDashboard, label: 'Dashboard',       roles: null },
  { to: '/pdo',          icon: List,            label: 'Daftar PDO',      roles: null },
  { to: '/transfer',     icon: ArrowRightLeft,  label: 'Transfer Dana',   roles: null },
  { to: '/realisasi',    icon: Wallet,          label: 'Realisasi Dana',  roles: null },
  { to: '/pdo-tambahan', icon: FilePlus,        label: 'PDO Tambahan',    roles: null },
  { to: '/laporan',      icon: BarChart2,       label: 'Laporan',         roles: null },
  { to: '/rekapitulasi', icon: BarChart3,       label: 'Rekapitulasi',    roles: null },
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
      className="flex flex-col h-screen sticky top-0 overflow-hidden"
      style={{ background: '#0c3d2c', width: 282, minWidth: 282, padding: '24px 18px' }}
    >
      {/* Brand */}
      <div className="flex items-center gap-3 mb-8">
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

      {/* Navigation */}
      <nav className="flex flex-col gap-1 flex-1">
        {visibleItems.map(({ to, icon: Icon, label }) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 px-[13px] py-3 rounded-[10px] text-[14px] font-[750] transition-colors',
                'text-[#d9f5e7] no-underline',
                isActive
                  ? 'bg-[rgba(255,255,255,.13)]'
                  : 'hover:bg-[rgba(255,255,255,.08)]'
              )
            }
          >
            <Icon className="w-4 h-4 shrink-0" />
            {label}
          </NavLink>
        ))}
      </nav>

      {/* User info at bottom */}
      {user && (
        <div className="mt-6 pt-4 border-t border-[rgba(255,255,255,.1)]">
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
