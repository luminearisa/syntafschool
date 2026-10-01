import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import ActivityCatalogPage from '@/features/activity/pages/ActivityCatalogPage';
import ActivityCertificateVerifyPage from '@/features/activity/pages/ActivityCertificateVerifyPage';
import ActivityDashboardPage from '@/features/activity/pages/ActivityDashboardPage';
import ActivityDetailPage from '@/features/activity/pages/ActivityDetailPage';
import ActivityMyPage from '@/features/activity/pages/ActivityMyPage';
import ActivityParentPage from '@/features/activity/pages/ActivityParentPage';
import ActivityWorkspacePage from '@/features/activity/pages/ActivityWorkspacePage';

/**
 * Modul Student Activity & Extracurricular.
 *
 * Otorisasi layar ditegakkan backend (`activity.*`); guard di sini hanya
 * menyembunyikan halaman yang pasti ditolak. Cakupan baris — pembina hanya
 * program yang ditugaskan, orang tua hanya anaknya, juri hanya penampilannya —
 * ditegakkan `ActivityAccessService`, termasuk bila ID ditebak.
 */
const guard = (permission: string | string[], element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'activities', element: guard('activity.view', <ActivityCatalogPage />) },
  { path: 'activities/:programId', element: guard('activity.view', <ActivityDetailPage />) },
  { path: 'activities/my', element: guard('activity.portal.view', <ActivityMyPage />) },
  { path: 'activities/workspace', element: guard('activity.program.view', <ActivityWorkspacePage />) },
  { path: 'activities/dashboard', element: guard('activity.view', <ActivityDashboardPage />) },
  { path: 'activities/parent', element: guard('activity.portal.view', <ActivityParentPage />) },
  { path: 'activities/verify', element: guard('activity.view', <ActivityCertificateVerifyPage />) },
];

export const activityMenu: MenuChild[] = [
  { label: 'Dasbor Aktivitas', to: '/activities/dashboard', permission: 'activity.view' },
  { label: 'Katalog Aktivitas', to: '/activities', permission: 'activity.view' },
  { label: 'Aktivitas Saya', to: '/activities/my', permission: 'activity.portal.view' },
  { label: 'Ruang Kerja Pembina', to: '/activities/workspace', permission: 'activity.program.view' },
  { label: 'Aktivitas Anak', to: '/activities/parent', permission: 'activity.portal.view' },
  { label: 'Verifikasi Sertifikat', to: '/activities/verify', permission: 'activity.view' },
];
