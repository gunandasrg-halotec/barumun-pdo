import { Outlet, Navigate } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'
import { ToastContainer } from '@/components/ui/Toast'
import { useAuthStore } from '@/store/auth.store'

export function AppLayout() {
  const user = useAuthStore((s) => s.user)

  if (!user) return <Navigate to="/login" replace />

  return (
    <div className="flex flex-col desk:grid desk:grid-cols-[282px_1fr] min-h-screen">
      <Sidebar />

      <div className="flex flex-col min-h-screen overflow-hidden">
        <Topbar />
        <main className="flex-1 overflow-auto px-5 py-5 desk:px-7 desk:py-[22px] desk:pb-10">
          <Outlet />
        </main>
      </div>

      <ToastContainer />
    </div>
  )
}
