import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { PpdbDashboardPage } from '../pages/ops/PpdbDashboardPage';
import { PpdbRegistrationsPage } from '../pages/ops/PpdbRegistrationsPage';
import { PpdbRegistrationDetailPage } from '../pages/ops/PpdbRegistrationDetailPage';
import { PpdbVerificationPage } from '../pages/ops/PpdbVerificationPage';
import { PpdbSelectionPage } from '../pages/ops/PpdbSelectionPage';
import { PpdbAnnouncementsPage } from '../pages/ops/PpdbAnnouncementsPage';
import { PpdbPaymentsPage } from '../pages/ops/PpdbPaymentsPage';
import { PpdbAppealsPage } from '../pages/ops/PpdbAppealsPage';
import { PpdbParentAccountsPage } from '../pages/ops/PpdbParentAccountsPage';
import { PpdbApplicantsPage } from '../pages/ops/PpdbApplicantsPage';
import { PpdbReportsPage } from '../pages/ops/PpdbReportsPage';

/**
 * Path relatif agar `src/routes/domains` bisa menempelkannya langsung ke
 * `children` AppLayout; rute detail sengaja setelah daftar. Tidak diekspor
 * sebagai domainRoutes supaya penggabungan tetap di pusat.
 *
 * `/ppdb` sendiri ditinggal untuk halaman publik (lihat `portalRoutes`), jadi
 * dasbor panitia menempati `/ppdb/panel` — kalau keduanya pakai path yang sama,
 * rute publik yang lebih dulu dipasang akan menelan dasbor admin.
 */
export const ppdbOpsRoutes: RouteObject[] = [
  {
    path: 'ppdb/panel',
    element: (
      <PermissionGuard permission="ppdb.view">
        <PpdbDashboardPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/registrations',
    element: (
      <PermissionGuard permission="ppdb.registration.view">
        <PpdbRegistrationsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/registrations/:id',
    element: (
      <PermissionGuard permission="ppdb.registration.view">
        <PpdbRegistrationDetailPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/verification',
    element: (
      <PermissionGuard permission="ppdb.registration.verify">
        <PpdbVerificationPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/selection',
    element: (
      <PermissionGuard permission="ppdb.selection.manage">
        <PpdbSelectionPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/announcements',
    element: (
      <PermissionGuard permission="ppdb.announcement.manage">
        <PpdbAnnouncementsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/payments',
    element: (
      <PermissionGuard permission="ppdb.payment.view">
        <PpdbPaymentsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/appeals',
    element: (
      <PermissionGuard permission="ppdb.registration.view">
        <PpdbAppealsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/parent-accounts',
    element: (
      <PermissionGuard permission="ppdb.account.manage">
        <PpdbParentAccountsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/applicants',
    element: (
      <PermissionGuard permission="ppdb.view">
        <PpdbApplicantsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/reports',
    element: (
      <PermissionGuard permission="ppdb.report.view">
        <PpdbReportsPage />
      </PermissionGuard>
    ),
  },
];

/** Anak menu grup operasi PPDB dengan label Indonesia. */
export const ppdbOpsMenu: MenuChild[] = [
  { label: 'Dashboard PPDB', to: '/ppdb/panel', permission: 'ppdb.view' },
  { label: 'Pendaftaran', to: '/ppdb/registrations', permission: 'ppdb.registration.view' },
  { label: 'Verifikasi', to: '/ppdb/verification', permission: 'ppdb.registration.verify' },
  { label: 'Seleksi', to: '/ppdb/selection', permission: 'ppdb.selection.manage' },
  { label: 'Pengumuman', to: '/ppdb/announcements', permission: 'ppdb.announcement.manage' },
  { label: 'Pembayaran', to: '/ppdb/payments', permission: 'ppdb.payment.view' },
  { label: 'Masa Sanggah', to: '/ppdb/appeals', permission: 'ppdb.registration.view' },
  { label: 'Akun Orang Tua', to: '/ppdb/parent-accounts', permission: 'ppdb.account.manage' },
  { label: 'Calon Siswa', to: '/ppdb/applicants', permission: 'ppdb.view' },
  { label: 'Laporan', to: '/ppdb/reports', permission: 'ppdb.report.view' },
];
