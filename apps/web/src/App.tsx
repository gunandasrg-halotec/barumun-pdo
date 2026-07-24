import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { lazy, Suspense } from 'react'
import { Loader2 } from 'lucide-react'
import { useAuthStore } from '@/store/auth.store'
import { AppLayout } from '@/components/layout/AppLayout'

// LoginPage tetap eager: layar pertama yang dilihat user (hindari flash spinner saat masuk).
import { LoginPage } from '@/pages/LoginPage'

// Semua halaman di balik autentikasi di-lazy load supaya tiap halaman jadi chunk
// terpisah dan hanya diunduh saat rutenya dibuka (mengecilkan bundle awal).
const DashboardPage              = lazy(() => import('@/pages/DashboardPage').then((m) => ({ default: m.DashboardPage })))
const MasterDataPage             = lazy(() => import('@/pages/MasterDataPage').then((m) => ({ default: m.MasterDataPage })))
const KategoriFormPage           = lazy(() => import('@/pages/KategoriFormPage').then((m) => ({ default: m.KategoriFormPage })))
const SubKategoriFormPage        = lazy(() => import('@/pages/SubKategoriFormPage').then((m) => ({ default: m.SubKategoriFormPage })))
const ItemFormPage               = lazy(() => import('@/pages/ItemFormPage').then((m) => ({ default: m.ItemFormPage })))
const UserFormPage               = lazy(() => import('@/pages/UserFormPage').then((m) => ({ default: m.UserFormPage })))
const PdoListPage                = lazy(() => import('@/pages/PdoListPage').then((m) => ({ default: m.PdoListPage })))
const PdoFormPage                = lazy(() => import('@/pages/PdoFormPage').then((m) => ({ default: m.PdoFormPage })))
const PdoDetailPage              = lazy(() => import('@/pages/PdoDetailPage').then((m) => ({ default: m.PdoDetailPage })))
const ApprovalTimelinePage       = lazy(() => import('@/pages/ApprovalTimelinePage').then((m) => ({ default: m.ApprovalTimelinePage })))
const TransferPage               = lazy(() => import('@/pages/TransferPage').then((m) => ({ default: m.TransferPage })))
const TransferBulkPage           = lazy(() => import('@/pages/TransferBulkPage').then((m) => ({ default: m.TransferBulkPage })))
const TransferInstructionsPage   = lazy(() => import('@/pages/TransferInstructionsPage').then((m) => ({ default: m.TransferInstructionsPage })))
const RealizationPage            = lazy(() => import('@/pages/RealizationPage').then((m) => ({ default: m.RealizationPage })))
const PdoSupplementaryListPage   = lazy(() => import('@/pages/PdoSupplementaryListPage').then((m) => ({ default: m.PdoSupplementaryListPage })))
const PdoSupplementaryFormPage   = lazy(() => import('@/pages/PdoSupplementaryFormPage').then((m) => ({ default: m.PdoSupplementaryFormPage })))
const PdoSupplementaryDetailPage = lazy(() => import('@/pages/PdoSupplementaryDetailPage').then((m) => ({ default: m.PdoSupplementaryDetailPage })))
const LaporanPage                = lazy(() => import('@/pages/LaporanPage').then((m) => ({ default: m.LaporanPage })))
const RekapitulasiPage           = lazy(() => import('@/pages/RekapitulasiPage').then((m) => ({ default: m.RekapitulasiPage })))
const SettingsPage               = lazy(() => import('@/pages/SettingsPage').then((m) => ({ default: m.SettingsPage })))
const VehicleFormPage            = lazy(() => import('@/pages/VehicleFormPage'))
const VehicleTripLogPage         = lazy(() => import('@/pages/VehicleTripLogPage'))

function RequireAuth({ children }: { children: React.ReactNode }) {
  const token = useAuthStore((s) => s.token)
  return token ? <>{children}</> : <Navigate to="/login" replace />
}

function PageFallback() {
  return (
    <div className="flex items-center justify-center py-20 text-muted">
      <Loader2 className="w-6 h-6 animate-spin" />
    </div>
  )
}

export default function App() {
  return (
    <BrowserRouter>
      <Suspense fallback={<PageFallback />}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />

          <Route
            path="/"
            element={
              <RequireAuth>
                <AppLayout />
              </RequireAuth>
            }
          >
            <Route index element={<Navigate to="/dashboard" replace />} />
            <Route path="dashboard" element={<DashboardPage />} />

            {/* Master Data */}
            <Route path="master" element={<MasterDataPage />} />
            <Route path="master/kategori/buat"            element={<KategoriFormPage />} />
            <Route path="master/kategori/:id/edit"        element={<KategoriFormPage />} />
            <Route path="master/sub-kategori/buat"        element={<SubKategoriFormPage />} />
            <Route path="master/sub-kategori/:id/edit"    element={<SubKategoriFormPage />} />
            <Route path="master/item/buat"                element={<ItemFormPage />} />
            <Route path="master/item/:id/edit"            element={<ItemFormPage />} />
            <Route path="master/user/buat"                element={<UserFormPage />} />
            <Route path="master/user/:id/edit"            element={<UserFormPage />} />
            <Route path="master/kendaraan/buat"           element={<VehicleFormPage />} />
            <Route path="master/kendaraan/:id/edit"       element={<VehicleFormPage />} />

            {/* PDO Bulanan */}
            <Route path="pdo"              element={<PdoListPage />} />
            <Route path="pdo/buat"         element={<PdoFormPage />} />
            <Route path="pdo/:id"          element={<PdoDetailPage />} />
            <Route path="pdo/:id/edit"     element={<PdoFormPage />} />
            <Route path="pdo/:id/approval" element={<ApprovalTimelinePage />} />

            {/* Transfer & Realisasi */}
            <Route path="transfer"           element={<TransferPage />} />
            <Route path="transfer/:pdoId"  element={<TransferBulkPage />} />
            <Route path="perintah-transfer" element={<TransferInstructionsPage />} />
            <Route path="realisasi"        element={<RealizationPage />} />

            {/* PDO Tambahan */}
            <Route path="pdo-tambahan"              element={<PdoSupplementaryListPage />} />
            <Route path="pdo-tambahan/buat"         element={<PdoSupplementaryFormPage />} />
            <Route path="pdo-tambahan/:id"          element={<PdoSupplementaryDetailPage />} />
            <Route path="pdo-tambahan/:id/edit"     element={<PdoSupplementaryFormPage />} />

            {/* Laporan & Settings */}
            <Route path="laporan"        element={<LaporanPage />} />
            <Route path="rekapitulasi"        element={<RekapitulasiPage />} />
            <Route path="log-trip-kendaraan"  element={<VehicleTripLogPage />} />
            <Route path="settings"       element={<SettingsPage />} />
          </Route>

          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  )
}
