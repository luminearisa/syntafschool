import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import AssetDashboardPage from '@/features/asset/pages/AssetDashboardPage';
import AssetListPage from '@/features/asset/pages/AssetListPage';
import AssetDetailPage from '@/features/asset/pages/AssetDetailPage';
import AssetLabelPrintPage from '@/features/asset/pages/AssetLabelPrintPage';
import AssetLoanListPage from '@/features/asset/pages/AssetLoanListPage';
import AssetTransferListPage from '@/features/asset/pages/AssetTransferListPage';
import MaintenanceListPage from '@/features/asset/pages/MaintenanceListPage';
import MaintenanceSchedulePage from '@/features/asset/pages/MaintenanceSchedulePage';
import InspectionListPage from '@/features/asset/pages/InspectionListPage';
import FacilityListPage from '@/features/asset/pages/FacilityListPage';
import FacilityBookingPage from '@/features/asset/pages/FacilityBookingPage';
import StockOpnameListPage from '@/features/asset/pages/StockOpnameListPage';
import StockOpnameSessionPage from '@/features/asset/pages/StockOpnameSessionPage';
import DisposalListPage from '@/features/asset/pages/DisposalListPage';
import AssetReportPage from '@/features/asset/pages/AssetReportPage';
import AssetLocationPage from '@/features/asset/pages/AssetLocationPage';

/**
 * Modul Aset & Sarpras.
 *
 * Otorisasi sebenarnya ditegakkan backend (`asset.*`, `facility.*`,
 * `location.*`); guard di sini hanya menyembunyikan halaman yang pasti ditolak
 * agar pengguna tidak menabrak dinding. Dua hal TIDAK diserahkan ke frontend:
 * angka keuangan (tidak dikirim bila tanpa izin) dan keputusan selisih opname
 * (selalu lewat izin `asset.stockopname.approve`).
 */
const guard = (permission: string | string[], element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'assets', element: guard('asset.view', <AssetListPage />) },
  { path: 'assets/dashboard', element: guard('asset.view', <AssetDashboardPage />) },
  { path: 'assets/labels', element: guard('asset.view', <AssetLabelPrintPage />) },
  { path: 'assets/loans', element: guard('asset.loan.view', <AssetLoanListPage />) },
  { path: 'assets/transfers', element: guard('asset.transfer.view', <AssetTransferListPage />) },
  { path: 'assets/maintenance', element: guard('asset.maintenance.view', <MaintenanceListPage />) },
  { path: 'assets/maintenance/schedules', element: guard('asset.maintenance.view', <MaintenanceSchedulePage />) },
  { path: 'assets/inspections', element: guard('asset.inspect.view', <InspectionListPage />) },
  { path: 'assets/facilities', element: guard('facility.view', <FacilityListPage />) },
  { path: 'assets/facilities/:facilityId/booking', element: guard('facility.view', <FacilityBookingPage />) },
  { path: 'assets/stock-opnames', element: guard('asset.stockopname.view', <StockOpnameListPage />) },
  { path: 'assets/stock-opnames/:opnameId', element: guard('asset.stockopname.view', <StockOpnameSessionPage />) },
  { path: 'assets/disposals', element: guard('asset.disposal.view', <DisposalListPage />) },
  { path: 'assets/reports', element: guard('asset.view', <AssetReportPage />) },
  { path: 'assets/locations', element: guard('location.view', <AssetLocationPage />) },
  { path: 'assets/:assetId', element: guard('asset.view', <AssetDetailPage />) },
];

export const assetMenu: MenuChild[] = [
  { label: 'Dasbor Aset', to: '/assets/dashboard', permission: 'asset.view' },
  { label: 'Daftar Aset', to: '/assets', permission: 'asset.view' },
  { label: 'Label & QR', to: '/assets/labels', permission: 'asset.view' },
  { label: 'Peminjaman', to: '/assets/loans', permission: 'asset.loan.view' },
  { label: 'Pemindahan', to: '/assets/transfers', permission: 'asset.transfer.view' },
  { label: 'Pemeliharaan', to: '/assets/maintenance', permission: 'asset.maintenance.view' },
  { label: 'Jadwal Preventif', to: '/assets/maintenance/schedules', permission: 'asset.maintenance.view' },
  { label: 'Inspeksi', to: '/assets/inspections', permission: 'asset.inspect.view' },
  { label: 'Fasilitas', to: '/assets/facilities', permission: 'facility.view' },
  { label: 'Stok Opname', to: '/assets/stock-opnames', permission: 'asset.stockopname.view' },
  { label: 'Penghapusan', to: '/assets/disposals', permission: 'asset.disposal.view' },
  { label: 'Struktur Lokasi', to: '/assets/locations', permission: 'location.view' },
  { label: 'Laporan Aset', to: '/assets/reports', permission: 'asset.view' },
];
