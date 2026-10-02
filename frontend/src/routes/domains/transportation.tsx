import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import TransportationDashboardPage from '@/features/transportation/pages/TransportationDashboardPage';
import TransportationDispatchBoardPage from '@/features/transportation/pages/TransportationDispatchBoardPage';
import TransportationTripsPage from '@/features/transportation/pages/TransportationTripsPage';
import TransportationFleetPage from '@/features/transportation/pages/TransportationFleetPage';
import TransportationDriversPage from '@/features/transportation/pages/TransportationDriversPage';
import TransportationRoutesPage from '@/features/transportation/pages/TransportationRoutesPage';
import TransportationStudentsPage from '@/features/transportation/pages/TransportationStudentsPage';
import TransportationIncidentsPage from '@/features/transportation/pages/TransportationIncidentsPage';
import TransportationLostFoundPage from '@/features/transportation/pages/TransportationLostFoundPage';
import TransportationReportsPage from '@/features/transportation/pages/TransportationReportsPage';
import DriverAppPage from '@/features/transportation/pages/DriverAppPage';
import ParentTransportationPage from '@/features/transportation/pages/ParentTransportationPage';

/**
 * Modul School Operations — Transportation Management (§95).
 *
 * Otorisasi layar ditegakkan backend (`transportation.*`); guard di sini hanya
 * menyembunyikan halaman yang pasti ditolak. Cakupan baris — wali hanya anaknya,
 * pengemudi hanya trip yang ditugaskan — ditegakkan service, termasuk bila ID
 * ditebak langsung ke API.
 */
const guard = (permission: string | string[], element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'transportation', element: guard('transportation.view', <TransportationDashboardPage />) },
  { path: 'transportation/dispatch', element: guard(['transportation.dispatch.view', 'transportation.trip.dispatch'], <TransportationDispatchBoardPage />) },
  { path: 'transportation/trips', element: guard('transportation.trip.view', <TransportationTripsPage />) },
  { path: 'transportation/fleet', element: guard('transportation.fleet.view', <TransportationFleetPage />) },
  { path: 'transportation/drivers', element: guard('transportation.driver.view', <TransportationDriversPage />) },
  { path: 'transportation/routes', element: guard('transportation.route.view', <TransportationRoutesPage />) },
  { path: 'transportation/students', element: guard(['transportation.student.view', 'transportation.request.view'], <TransportationStudentsPage />) },
  { path: 'transportation/incidents', element: guard('transportation.incident.view', <TransportationIncidentsPage />) },
  { path: 'transportation/lost-found', element: guard('transportation.lostfound.view', <TransportationLostFoundPage />) },
  { path: 'transportation/reports', element: guard(['transportation.report.view', 'transportation.analytics.view'], <TransportationReportsPage />) },
  { path: 'transportation/driver-app', element: guard(['transportation.driver_app.use', 'transportation.trip.operate'], <DriverAppPage />) },
  { path: 'transportation/portal', element: guard('transportation.portal.view', <ParentTransportationPage />) },
];

export const transportationMenu: MenuChild[] = [
  { label: 'Dashboard', to: '/transportation', permission: 'transportation.view' },
  { label: 'Papan Dispatch', to: '/transportation/dispatch', permission: ['transportation.dispatch.view', 'transportation.trip.dispatch'] },
  { label: 'Trip & Manifes', to: '/transportation/trips', permission: 'transportation.trip.view' },
  { label: 'Kalender Trip', to: '/transportation/trips?tab=calendar', permission: 'transportation.trip.view' },
  { label: 'Riwayat Trip', to: '/transportation/trips?tab=history', permission: 'transportation.trip.view' },
  { label: 'Kendaraan', to: '/transportation/fleet', permission: 'transportation.fleet.view' },
  { label: 'Dokumen & BBM', to: '/transportation/fleet?tab=documents', permission: 'transportation.fleet.view' },
  { label: 'Inspeksi', to: '/transportation/fleet?tab=inspections', permission: 'transportation.inspection.view' },
  { label: 'Pengemudi', to: '/transportation/drivers', permission: 'transportation.driver.view' },
  { label: 'SIM & Kualifikasi', to: '/transportation/drivers?tab=licenses', permission: 'transportation.driver.view' },
  { label: 'Rute & Halte', to: '/transportation/routes', permission: 'transportation.route.view' },
  { label: 'Penugasan Siswa', to: '/transportation/students', permission: ['transportation.student.view', 'transportation.request.view'] },
  { label: 'Permintaan & Daftar Tunggu', to: '/transportation/students?tab=requests', permission: 'transportation.request.view' },
  { label: 'Insiden & Temuan', to: '/transportation/incidents', permission: 'transportation.incident.view' },
  { label: 'Barang Hilang', to: '/transportation/lost-found', permission: 'transportation.lostfound.view' },
  { label: 'Analitik & Laporan', to: '/transportation/reports', permission: ['transportation.report.view', 'transportation.analytics.view'] },
  { label: 'Aplikasi Pengemudi', to: '/transportation/driver-app', permission: ['transportation.driver_app.use', 'transportation.trip.operate'] },
  { label: 'Portal Wali', to: '/transportation/portal', permission: 'transportation.portal.view' },
];
