import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { BoardingDashboardPage } from '@/features/boarding/pages/BoardingDashboardPage';
import { BoardingStructurePage } from '@/features/boarding/pages/BoardingStructurePage';
import { BoardingPlacementsPage } from '@/features/boarding/pages/BoardingPlacementsPage';
import { BoardingItemDepositsPage } from '@/features/boarding/pages/BoardingItemDepositsPage';
import { BoardingCaregiversPage } from '@/features/boarding/pages/BoardingCaregiversPage';
import { BoardingActivitiesPage } from '@/features/boarding/pages/BoardingActivitiesPage';
import { BoardingAttendancePage } from '@/features/boarding/pages/BoardingAttendancePage';
import { BoardingPermissionsPage } from '@/features/boarding/pages/BoardingPermissionsPage';
import { BoardingHomecomingsPage } from '@/features/boarding/pages/BoardingHomecomingsPage';
import { BoardingVisitorsPage } from '@/features/boarding/pages/BoardingVisitorsPage';
import { BoardingViolationsPage } from '@/features/boarding/pages/BoardingViolationsPage';
import { BoardingAchievementsPage } from '@/features/boarding/pages/BoardingAchievementsPage';
import { BoardingHealthPage } from '@/features/boarding/pages/BoardingHealthPage';
import { BoardingMonitoringPage } from '@/features/boarding/pages/BoardingMonitoringPage';
import { BoardingReportsPage } from '@/features/boarding/pages/BoardingReportsPage';
import { BoardingSettingsPage } from '@/features/boarding/pages/BoardingSettingsPage';
import { BoardingPortalPage } from '@/features/boarding/pages/BoardingPortalPage';

/**
 * Modul Boarding / Pesantren.
 *
 * `domainRoutes` diglob otomatis oleh `src/routes/domains/index.ts` dan
 * dipasang di dalam cangkang aplikasi (sudah lewat ProtectedRoute). Otorisasi
 * tetap ditegakkan di backend per-route (`permission:` middleware) — guard di
 * sini hanya mencerminkan permission yang sama agar pengguna tidak dihadapkan
 * pada 403 tak perlu.
 *
 * `boardingMenu` SENGAJA tidak dinamai `domainMenu`: `domainMenu` digabung ke
 * grup "Akademik", sedangkan Boarding punya grup menu sendiri (lihat
 * `src/constants/menu.ts`).
 *
 * Portal orang tua (`/boarding/portal`) TIDAK memakai PermissionGuard: orang
 * tua tidak punya permission boarding apa pun (larangan di spesifikasi), jadi
 * aksesnya digating server-side lewat kepemilikan anak (`assertChild`).
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'boarding',
    element: (
      <PermissionGuard permission="boarding.dashboard.view">
        <BoardingDashboardPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/structure',
    element: (
      <PermissionGuard
        permission={[
          'boarding.dorm.manage',
          'boarding.building.manage',
          'boarding.floor.manage',
          'boarding.room.manage',
          'boarding.bed.manage',
        ]}
      >
        <BoardingStructurePage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/placements',
    element: (
      <PermissionGuard permission="boarding.placement.view">
        <BoardingPlacementsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/caregivers',
    element: (
      <PermissionGuard permission={['boarding.musyrif.view', 'boarding.group.view']}>
        <BoardingCaregiversPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/activities',
    element: (
      <PermissionGuard permission={['boarding.activity.view', 'boarding.schedule.view']}>
        <BoardingActivitiesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/attendance',
    element: (
      <PermissionGuard permission="boarding.attendance.view">
        <BoardingAttendancePage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/permissions',
    element: (
      <PermissionGuard permission="boarding.permission.view">
        <BoardingPermissionsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/homecomings',
    element: (
      <PermissionGuard permission="boarding.homecoming.view">
        <BoardingHomecomingsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/visitors',
    element: (
      <PermissionGuard permission="boarding.visitor.view">
        <BoardingVisitorsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/violations',
    element: (
      <PermissionGuard permission="boarding.violation.view">
        <BoardingViolationsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/achievements',
    element: (
      <PermissionGuard permission="boarding.achievement.view">
        <BoardingAchievementsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/health',
    element: (
      <PermissionGuard permission="boarding.health.view">
        <BoardingHealthPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/item-deposits',
    element: (
      <PermissionGuard permission="boarding.deposit.view">
        <BoardingItemDepositsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/monitoring',
    element: (
      <PermissionGuard permission="boarding.dashboard.view">
        <BoardingMonitoringPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/reports',
    element: (
      <PermissionGuard permission={['boarding.report.view', 'boarding.audit.view']}>
        <BoardingReportsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'boarding/settings',
    element: (
      <PermissionGuard permission="boarding.settings.view">
        <BoardingSettingsPage />
      </PermissionGuard>
    ),
  },
  {
    // Portal orang tua — gating oleh kepemilikan anak, bukan permission.
    path: 'boarding/portal',
    element: <BoardingPortalPage />,
  },
];

export const boardingMenu: MenuChild[] = [
  { label: 'Dashboard Boarding', to: '/boarding', permission: 'boarding.dashboard.view' },
  { label: 'Monitoring Santri', to: '/boarding/monitoring', permission: 'boarding.dashboard.view' },
  { label: 'Struktur Asrama', to: '/boarding/structure', permission: 'boarding.dorm.manage' },
  { label: 'Penempatan Santri', to: '/boarding/placements', permission: 'boarding.placement.view' },
  { label: 'Pengasuh & Kelompok', to: '/boarding/caregivers', permission: 'boarding.musyrif.view' },
  { label: 'Kegiatan & Jadwal', to: '/boarding/activities', permission: 'boarding.activity.view' },
  { label: 'Absensi', to: '/boarding/attendance', permission: 'boarding.attendance.view' },
  { label: 'Izin Keluar', to: '/boarding/permissions', permission: 'boarding.permission.view' },
  { label: 'Kepulangan', to: '/boarding/homecomings', permission: 'boarding.homecoming.view' },
  { label: 'Kunjungan Tamu', to: '/boarding/visitors', permission: 'boarding.visitor.view' },
  { label: 'Kedisiplinan', to: '/boarding/violations', permission: 'boarding.violation.view' },
  { label: 'Prestasi', to: '/boarding/achievements', permission: 'boarding.achievement.view' },
  { label: 'Kesehatan & Isolasi', to: '/boarding/health', permission: 'boarding.health.view' },
  { label: 'Titipan Barang', to: '/boarding/item-deposits', permission: 'boarding.deposit.view' },
  { label: 'Laporan & Audit', to: '/boarding/reports', permission: 'boarding.report.view' },
  { label: 'Pengaturan Boarding', to: '/boarding/settings', permission: 'boarding.settings.view' },
  // Tanpa `permission`: grup menu boarding tetap muncul untuk orang tua yang
  // tidak memiliki permission apa pun di modul ini.
  { label: 'Portal Orang Tua', to: '/boarding/portal' },
];
