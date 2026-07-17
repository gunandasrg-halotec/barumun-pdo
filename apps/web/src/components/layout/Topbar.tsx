import { useState } from 'react'
import { LogOut } from 'lucide-react'
import { useAuthStore } from '@/store/auth.store'
import { useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import { clearTokenRefreshTimer } from '@/lib/tokenRefresh'
import { getInitials } from '@/lib/format'
import { ROLE_LABELS } from '@/lib/auth'
import type { RoleCode } from '@/types'

export function Topbar() {
  const { user, logout } = useAuthStore()
  const navigate = useNavigate()
  const [showMenu, setShowMenu] = useState(false)

  const handleLogout = async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      clearTokenRefreshTimer()
      logout()
      navigate('/login')
    }
  }

  return (
    <header className="flex items-center justify-end px-7 py-4 bg-white border-b border-line sticky top-0 z-20">
      {/* Profile */}
      {user && (
        <div className="relative">
          <button
            className="flex items-center gap-2 cursor-pointer"
            onClick={() => setShowMenu((v) => !v)}
          >
            <span className="badge badge-draft text-xs">
              {ROLE_LABELS[user.role.code as RoleCode]}
            </span>
            <div
              className="w-[38px] h-[38px] rounded-full flex items-center justify-center text-white text-[13px] font-[800]"
              style={{ background: '#0f6b45' }}
            >
              {getInitials(user.full_name)}
            </div>
          </button>

          {showMenu && (
            <div className="absolute right-0 top-12 w-48 bg-white rounded-card shadow-card border border-line z-30 py-1">
              <div className="px-4 py-2 border-b border-line">
                <div className="text-sm font-bold text-ink truncate">{user.full_name}</div>
                <div className="text-xs text-muted truncate">{user.email}</div>
              </div>
              <button
                onClick={handleLogout}
                className="flex items-center gap-2 w-full px-4 py-2.5 text-sm text-red hover:bg-red-50 transition-colors"
              >
                <LogOut className="w-4 h-4" />
                Keluar
              </button>
            </div>
          )}
        </div>
      )}
    </header>
  )
}
