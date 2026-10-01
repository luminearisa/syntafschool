import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import ActivityCatalogPage from '@/features/activity/pages/ActivityCatalogPage';
import ActivityCompetitionsPage from '@/features/activity/pages/ActivityCompetitionsPage';
import ActivityPortfolioPage from '@/features/activity/pages/ActivityPortfolioPage';
import ActivityPublicPortfolioPage from '@/features/activity/pages/ActivityPublicPortfolioPage';
import ActivityRecognitionPage from '@/features/activity/pages/ActivityRecognitionPage';
import ActivityCertificateVerifyPage from '@/features/activity/pages/ActivityCertificateVerifyPage';
import ActivityDashboardPage from '@/features/activity/pages/ActivityDashboardPage';
import ActivityGovernancePage from '@/features/activity/pages/ActivityGovernancePage';
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
  { path: 'activities/portfolio', element: guard('activity.portfolio.view', <ActivityPortfolioPage />) },
  {
    path: 'activities/competitions',
    element: guard(['activity.competition.view', 'activity.competition.judge'], <ActivityCompetitionsPage />),
  },
  {
    path: 'activities/recognition',
    element: guard(['activity.achievement.view', 'activity.certificate.view'], <ActivityRecognitionPage />),
  },
  {
    path: 'activities/governance',
    element: guard(
      ['activity.audit.view', 'activity.consent.view', 'activity.finance.view', 'activity.inventory.view'],
      <ActivityGovernancePage />,
    ),
  },
];

/**
 * Portofolio publik — di luar cangkang aplikasi, dapat dibuka tanpa login.
 * Tautan hanya hidup bila sekolah mengaktifkannya; identitas siswa disamarkan.
 */
export const domainPublicRoutes: RouteObject[] = [
  { path: '/aktivitas/portofolio/:token', element: <ActivityPublicPortfolioPage /> },
];

export const activityMenu: MenuChild[] = [
  { label: 'Dasbor Aktivitas', to: '/activities/dashboard', permission: 'activity.view' },
  { label: 'Katalog Aktivitas', to: '/activities', permission: 'activity.view' },
  { label: 'Aktivitas Saya', to: '/activities/my', permission: 'activity.portal.view' },
  { label: 'Ruang Kerja Pembina', to: '/activities/workspace', permission: 'activity.program.view' },
  { label: 'Aktivitas Anak', to: '/activities/parent', permission: 'activity.portal.view' },
  { label: 'Verifikasi Sertifikat', to: '/activities/verify', permission: 'activity.view' },
  { label: 'Portofolio & Transkrip', to: '/activities/portfolio', permission: 'activity.portfolio.view' },
  { label: 'Kompetisi', to: '/activities/competitions', permission: ['activity.competition.view', 'activity.competition.judge'] },
  { label: 'Prestasi & Sertifikat', to: '/activities/recognition', permission: ['activity.achievement.view', 'activity.certificate.view'] },
  {
    label: 'Tata Kelola & Audit',
    to: '/activities/governance',
    permission: ['activity.audit.view', 'activity.consent.view', 'activity.finance.view', 'activity.inventory.view'],
  },
];
