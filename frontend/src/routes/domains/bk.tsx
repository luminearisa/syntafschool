import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import BkAppointmentsPage from '@/features/bk/pages/BkAppointmentsPage';
import BkCaseDetailPage from '@/features/bk/pages/BkCaseDetailPage';
import BkCasesPage from '@/features/bk/pages/BkCasesPage';
import BkDashboardPage from '@/features/bk/pages/BkDashboardPage';
import BkPortalPage from '@/features/bk/pages/BkPortalPage';
import BkReferralsPage from '@/features/bk/pages/BkReferralsPage';
import BkReportsPage from '@/features/bk/pages/BkReportsPage';

/**
 * Modul Bimbingan & Konseling (BK).
 *
 * Guard di sini hanya menyembunyikan halaman yang pasti ditolak backend.
 * Cakupan baris — konselor hanya kasusnya, wali hanya anaknya, guru hanya yang
 * dibagikan — ditegakkan `BkAccessService`; tab konseling tidak pernah muncul
 * bagi guru/siswa/wali karena izinnya memang tidak mereka pegang.
 */
const guard = (permission: string | string[], element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'bk', element: guard('bk.view', <BkDashboardPage />) },
  { path: 'bk/referrals', element: guard('bk.referral.view', <BkReferralsPage />) },
  { path: 'bk/cases', element: guard('bk.case.view', <BkCasesPage />) },
  { path: 'bk/cases/:id', element: guard('bk.case.view', <BkCaseDetailPage />) },
  { path: 'bk/appointments', element: guard('bk.appointment.view', <BkAppointmentsPage />) },
  { path: 'bk/reports', element: guard('bk.report.view', <BkReportsPage />) },
  { path: 'bk/portal', element: guard('bk.portal.view', <BkPortalPage />) },
];

export const bkMenu: MenuChild[] = [
  { label: 'Dasbor BK', to: '/bk', permission: 'bk.view' },
  { label: 'Referral', to: '/bk/referrals', permission: 'bk.referral.view' },
  { label: 'Kasus', to: '/bk/cases', permission: 'bk.case.view' },
  { label: 'Janji Temu', to: '/bk/appointments', permission: 'bk.appointment.view' },
  { label: 'Laporan BK', to: '/bk/reports', permission: 'bk.report.view' },
  { label: 'Portal Saya', to: '/bk/portal', permission: 'bk.portal.view' },
];
