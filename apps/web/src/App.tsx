import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { useAuthStore } from '@/store/auth.store'
import { AppLayout } from '@/components/layout/AppLayout'

// Pages
import { LoginPage }                    from '@/pages/LoginPage'
import { DashboardPage }                from '@/pages/DashboardPage'
import { MasterDataPage }               from '@/pages/MasterDataPage'
import { KategoriFormPage }             from '@/pages/KategoriFormPage'
import { SubKategoriFormPage }          from '@/pages/SubKategoriFormPage'
import { ItemFormPage }                 from '@/pages/ItemFormPage'
import { UserFormPage }                 from '@/pages/UserFormPage'
import { PdoListPage }                  from '@/pages/PdoListPage'
import { PdoFormPage }                  from '@/pages/PdoFormPage'
import { PdoDetailPage }                from '@/pages/PdoDetailPage'
import { ApprovalTimelinePage }         from '@/pages/ApprovalTimelinePage'
import { TransferPage }                 from '@/pages/TransferPage'
import { TransferBulkPage }             from '@/pages/TransferBulkPage'
import { RealizationPage }              from '@/pages/RealizationPage'
import { PdoSupplementaryListPage }     from '@/pages/PdoSupplementaryListPage'
import { PdoSupplementaryFormPage }     from '@/pages/PdoSupplementaryFormPage'
import { PdoSupplementaryDetailPage }   from '@/pages/PdoSupplementaryDetailPage'
import { LaporanPage }                  from '@/pages/LaporanPage'
import { RekapitulasiPage }             from '@/pages/RekapitulasiPage'
import { SettingsPage }                 from '@/pages/SettingsPage'

function RequireAuth({ children }: { children: React.ReactNode }) {
  const token = useAuthStore((s) => s.token)
  return token ? <>{children}</> : <Navigate to="/login" replace />
}

export default function App() {
  return (
    <BrowserRouter>
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

          {/* PDO Bulanan */}
          <Route path="pdo"              element={<PdoListPage />} />
          <Route path="pdo/buat"         element={<PdoFormPage />} />
          <Route path="pdo/:id"          element={<PdoDetailPage />} />
          <Route path="pdo/:id/edit"     element={<PdoFormPage />} />
          <Route path="pdo/:id/approval" element={<ApprovalTimelinePage />} />

          {/* Transfer & Realisasi */}
          <Route path="transfer"           element={<TransferPage />} />
          <Route path="transfer/:pdoId"  element={<TransferBulkPage />} />
          <Route path="realisasi"        element={<RealizationPage />} />

          {/* PDO Tambahan */}
          <Route path="pdo-tambahan"              element={<PdoSupplementaryListPage />} />
          <Route path="pdo-tambahan/buat"         element={<PdoSupplementaryFormPage />} />
          <Route path="pdo-tambahan/:id"          element={<PdoSupplementaryDetailPage />} />
          <Route path="pdo-tambahan/:id/edit"     element={<PdoSupplementaryFormPage />} />

          {/* Laporan & Settings */}
          <Route path="laporan"        element={<LaporanPage />} />
          <Route path="rekapitulasi"   element={<RekapitulasiPage />} />
          <Route path="settings"       element={<SettingsPage />} />
        </Route>

        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
