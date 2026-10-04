import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import LaundryDashboardPage from '@/features/laundry/pages/LaundryDashboardPage';
import LaundryReceivePage from '@/features/laundry/pages/LaundryReceivePage';
import LaundryOrdersPage from '@/features/laundry/pages/LaundryOrdersPage';
import LaundryOrderDetailPage from '@/features/laundry/pages/LaundryOrderDetailPage';
import LaundryProcessingPage from '@/features/laundry/pages/LaundryProcessingPage';
import LaundryQuotaPage from '@/features/laundry/pages/LaundryQuotaPage';
import LaundryDeliveryPage from '@/features/laundry/pages/LaundryDeliveryPage';
import LaundryIncidentsPage from '@/features/laundry/pages/LaundryIncidentsPage';
import LaundryBagsPage from '@/features/laundry/pages/LaundryBagsPage';
import LaundryReportsPage from '@/features/laundry/pages/LaundryReportsPage';
import LaundryMasterPage from '@/features/laundry/pages/LaundryMasterPage';
import ParentLaundryPage from '@/features/laundry/pages/ParentLaundryPage';
import ParentLaundryOrderPage from '@/features/laundry/pages/ParentLaundryOrderPage';
import StudentLaundryPage from '@/features/laundry/pages/StudentLaundryPage';

/**
 * Modul School Operations — Laundry Management.
 *
 * Otorisasi layar ditegakkan backend (`laundry.*`); guard di sini hanya
 * menyembunyikan halaman yang pasti ditolak. Cakupan baris — wali hanya
 * anaknya, santri hanya dirinya — ditegakkan service, termasuk bila ID
 * ditebak langsung ke API.
 */
const guard = (permission: string | string[], element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'laundry', element: guard('laundry.dashboard.view', <LaundryDashboardPage />) },
  { path: 'laundry/receive', element: guard(['laundry.receive', 'laundry.create'], <LaundryReceivePage />) },
  { path: 'laundry/orders', element: guard('laundry.view', <LaundryOrdersPage />) },
  { path: 'laundry/orders/:id', element: guard('laundry.view', <LaundryOrderDetailPage />) },
  { path: 'laundry/processing', element: guard('laundry.process', <LaundryProcessingPage />) },
  { path: 'laundry/quota', element: guard('laundry.quota.view', <LaundryQuotaPage />) },
  { path: 'laundry/delivery', element: guard('laundry.deliver', <LaundryDeliveryPage />) },
  { path: 'laundry/incidents', element: guard('laundry.incident.view', <LaundryIncidentsPage />) },
  { path: 'laundry/bags', element: guard('laundry.view', <LaundryBagsPage />) },
  { path: 'laundry/reports', element: guard('laundry.report', <LaundryReportsPage />) },
  { path: 'laundry/master', element: guard(['laundry.service.manage', 'laundry.pricing.manage', 'laundry.policy.manage'], <LaundryMasterPage />) },
  { path: 'laundry/portal', element: guard('laundry.portal.view', <ParentLaundryPage />) },
  { path: 'laundry/portal/:studentId/orders/:orderId', element: guard('laundry.portal.view', <ParentLaundryOrderPage />) },
  { path: 'laundry/me', element: guard('laundry.portal.view', <StudentLaundryPage />) },
];

export const laundryMenu: MenuChild[] = [
  { label: 'Dashboard', to: '/laundry', permission: 'laundry.dashboard.view' },
  { label: 'Meja Penerimaan', to: '/laundry/receive', permission: ['laundry.receive', 'laundry.create'] },
  { label: 'Order', to: '/laundry/orders', permission: 'laundry.view' },
  { label: 'Papan Proses', to: '/laundry/processing', permission: 'laundry.process' },
  { label: 'Jatah Santri', to: '/laundry/quota', permission: 'laundry.quota.view' },
  { label: 'Serah Terima', to: '/laundry/delivery', permission: 'laundry.deliver' },
  { label: 'Insiden', to: '/laundry/incidents', permission: 'laundry.incident.view' },
  { label: 'Laundry Bag', to: '/laundry/bags', permission: 'laundry.view' },
  { label: 'Laporan', to: '/laundry/reports', permission: 'laundry.report' },
  { label: 'Master & Policy', to: '/laundry/master', permission: ['laundry.service.manage', 'laundry.pricing.manage', 'laundry.policy.manage'] },
  { label: 'Portal Wali', to: '/laundry/portal', permission: 'laundry.portal.view' },
  { label: 'Laundry Saya', to: '/laundry/me', permission: 'laundry.portal.view' },
];
