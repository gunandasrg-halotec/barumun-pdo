import { Outlet, Navigate } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'
import { ToastContainer } from '@/components/ui/Toast'
import { useAuthStore } from '@/store/auth.store'

export function AppLayout() {
  const user = useAuthStore((s) => s.user)

  if (!user) return <Navigate to="/login" replace />

  return (
    <div
      className="grid min-h-screen"
      style={{ gridTemplateColumns: '282px 1fr' }}
    >
      <Sidebar />

      <div className="flex flex-col min-h-screen overflow-hidden">
        <Topbar />
        <main
          className="flex-1 overflow-auto"
          style={{ padding: '22px 28px 40px' }}
        >
          <Outlet />
        </main>
      </div>

      <ToastContainer />
    </div>
  )
}
