import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { ReligiousActivitiesPage } from '@/features/religious/pages/ReligiousActivitiesPage';
import { ReligiousAttendancePage } from '@/features/religious/pages/ReligiousAttendancePage';
import { ReligiousBookingsPage } from '@/features/religious/pages/ReligiousBookingsPage';
import { ReligiousDashboardPage } from '@/features/religious/pages/ReligiousDashboardPage';
import { ReligiousFridayPage } from '@/features/religious/pages/ReligiousFridayPage';
import { ReligiousMosquesPage } from '@/features/religious/pages/ReligiousMosquesPage';
import { ReligiousPrayersPage } from '@/features/religious/pages/ReligiousPrayersPage';
import { ReligiousRamadanPage } from '@/features/religious/pages/ReligiousRamadanPage';
import { ReligiousParentPortalPage } from '@/features/religious/pages/ReligiousParentPortalPage';
import { ReligiousReportsPage } from '@/features/religious/pages/ReligiousReportsPage';

/**
 * Frontend modul Religious & Mosque Management.
 * Setiap halaman memakai izin yang sama dengan endpoint backend; aksi tulis
 * tetap dibatasi lagi di komponen dan diverifikasi oleh backend.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'religious',
    element: (
      <PermissionGuard permission="religious.view">
        <ReligiousDashboardPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'religious/mosques',
    element: (
      <PermissionGuard permission="religious.mosque.view">
        <ReligiousMosquesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'religious/prayers',
    element: (
      <PermissionGuard permission="religious.prayer.view">
        <ReligiousPrayersPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'religious/attendance',
    element: (
      <PermissionGuard permission="religious.attendance.view">
        <ReligiousAttendancePage />
      </PermissionGuard>
    ),
  },
  {
    path: 'religious/bookings',
    element: (
      <PermissionGuard permission="religious.booking.view">
        <ReligiousBookingsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'religious/activities',
    element: (
      <PermissionGuard permission="religious.activity.view">
        <ReligiousActivitiesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'religious/friday',
    element: (
      <PermissionGuard permission="religious.friday.view">
        <ReligiousFridayPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'religious/ramadan',
    element: (
      <PermissionGuard permission="religious.ramadan.view">
        <ReligiousRamadanPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'religious/portal/wali',
    element: (
      <PermissionGuard permission="religious.portal.parent">
        <ReligiousParentPortalPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'religious/reports',
    element: (
      <PermissionGuard permission={['religious.analytics.view', 'religious.report.view']}>
        <ReligiousReportsPage />
      </PermissionGuard>
    ),
  },
];

export const religiousMenu: MenuChild[] = [
  { label: 'Dashboard Keagamaan', to: '/religious', permission: 'religious.view' },
  { label: 'Masjid & Area', to: '/religious/mosques', permission: 'religious.mosque.view' },
  { label: 'Jadwal Shalat', to: '/religious/prayers', permission: 'religious.prayer.view' },
  { label: 'Presensi Shalat', to: '/religious/attendance', permission: 'religious.attendance.view' },
  { label: 'Pemesanan Masjid', to: '/religious/bookings', permission: 'religious.booking.view' },
  { label: 'Kegiatan Keagamaan', to: '/religious/activities', permission: 'religious.activity.view' },
  { label: 'Jumat & Khutbah', to: '/religious/friday', permission: 'religious.friday.view' },
  { label: 'Program Ramadan', to: '/religious/ramadan', permission: 'religious.ramadan.view' },
  { label: 'Laporan Religious', to: '/religious/reports', permission: ['religious.analytics.view', 'religious.report.view'] },
  { label: 'Portal Wali', to: '/religious/portal/wali', permission: 'religious.portal.parent' },
];
