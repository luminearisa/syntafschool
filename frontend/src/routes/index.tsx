import { createBrowserRouter, Navigate } from 'react-router-dom';
import { AppLayout } from '@/components/layout/AppLayout';
import { ProtectedRoute } from './ProtectedRoute';
import { PermissionGuard } from './PermissionGuard';
import LoginPage from '@/features/auth/pages/LoginPage';
import DashboardPage from '@/features/dashboard/pages/DashboardPage';
import { MasterDataPage } from '@/features/master-data/pages/MasterDataPage';
import { AcademicPeriodsPage } from '@/features/academics/pages/AcademicPeriodsPage';
import { ClassesPage } from '@/features/academics/pages/ClassesPage';
import { SchedulesPage } from '@/features/academics/pages/SchedulesPage';
import { UsersPage } from '@/features/rbac/pages/UsersPage';
import { RolesPage } from '@/features/rbac/pages/RolesPage';
import { EmployeesPage } from '@/features/employees/pages/EmployeesPage';
import { StudentsPage } from '@/features/students/pages/StudentsPage';
import { GuardiansPage } from '@/features/students/pages/GuardiansPage';
import NotFoundPage from '@/features/common/pages/NotFoundPage';
import { TahfizPublicRegistrationPage } from '@/features/tahfiz/pages/TahfizPublicRegistrationPage';
import { LibraryOpacPage } from '@/features/library/pages/LibraryOpacPage';
import { LibraryOpacDetailPage } from '@/features/library/pages/LibraryOpacDetailPage';
import DocumentVerificationPage from '@/features/supervisi/pages/DocumentVerificationPage';
import { MASTER_RESOURCES } from '@/constants/masterData';
import { domainPublicRoutes, domainRoutes } from './domains';

/** Route dinamis untuk seluruh master data (butuh master-data.view) */
const masterRoutes = MASTER_RESOURCES.map((def) => ({
  path: `master/${def.key}`,
  element: (
    <PermissionGuard permission="master-data.view">
      <MasterDataPage def={def} />
    </PermissionGuard>
  ),
}));

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  // Pendaftaran mandiri Tahfiz — publik, di luar cangkang aplikasi.
  { path: '/tahfiz/daftar', element: <TahfizPublicRegistrationPage /> },
  // Verifikasi dokumen supervisi — publik, target QR pada berkas PDF.
  { path: '/verifikasi-dokumen/:code', element: <DocumentVerificationPage /> },
  // OPAC (katalog publik perpustakaan) — dapat diakses tanpa login.
  { path: '/library/opac', element: <LibraryOpacPage /> },
  { path: '/library/opac/:slug', element: <LibraryOpacDetailPage /> },
  // Domain yang punya permukaan publik sendiri (PPDB: halaman periode, daftar,
  // login & portal orang tua). Diletakkan sebelum blok terlindungi agar path
  // statis mereka menang atas catch-all `*` di dalam AppLayout.
  ...domainPublicRoutes,
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: <DashboardPage /> },
          ...masterRoutes,
          ...domainRoutes,
          {
            path: 'academics/periods',
            element: (
              <PermissionGuard permission="academic.view">
                <AcademicPeriodsPage />
              </PermissionGuard>
            ),
          },
          {
            path: 'academics/classes',
            element: (
              <PermissionGuard permission="academic.view">
                <ClassesPage />
              </PermissionGuard>
            ),
          },
          {
            path: 'academics/schedules',
            element: (
              <PermissionGuard permission="academic.view">
                <SchedulesPage />
              </PermissionGuard>
            ),
          },
          {
            path: 'settings/users',
            element: (
              <PermissionGuard permission="user.view">
                <UsersPage />
              </PermissionGuard>
            ),
          },
          {
            path: 'settings/roles',
            element: (
              <PermissionGuard permission="user.view">
                <RolesPage />
              </PermissionGuard>
            ),
          },
          {
            path: 'employees',
            element: (
              <PermissionGuard permission="employee.view">
                <EmployeesPage />
              </PermissionGuard>
            ),
          },
          {
            path: 'students',
            element: (
              <PermissionGuard permission="student.view">
                <StudentsPage />
              </PermissionGuard>
            ),
          },
          {
            path: 'students/guardians',
            element: (
              <PermissionGuard permission="student.view">
                <GuardiansPage />
              </PermissionGuard>
            ),
          },
          { path: '404', element: <NotFoundPage /> },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
  { path: '*', element: <Navigate to="/404" replace /> },
]);
