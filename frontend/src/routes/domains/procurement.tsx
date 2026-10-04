import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import ProcurementDashboardPage from '@/features/procurement/pages/ProcurementDashboardPage';
import VendorListPage from '@/features/procurement/pages/VendorListPage';
import VendorDetailPage from '@/features/procurement/pages/VendorDetailPage';
import CatalogPage from '@/features/procurement/pages/CatalogPage';
import PurchaseRequestListPage from '@/features/procurement/pages/PurchaseRequestListPage';
import PurchaseRequestDetailPage from '@/features/procurement/pages/PurchaseRequestDetailPage';
import RfqListPage from '@/features/procurement/pages/RfqListPage';
import RfqDetailPage from '@/features/procurement/pages/RfqDetailPage';
import PurchaseOrderListPage from '@/features/procurement/pages/PurchaseOrderListPage';
import PurchaseOrderDetailPage from '@/features/procurement/pages/PurchaseOrderDetailPage';
import ReceivingListPage from '@/features/procurement/pages/ReceivingListPage';
import InvoiceListPage from '@/features/procurement/pages/InvoiceListPage';
import InvoiceDetailPage from '@/features/procurement/pages/InvoiceDetailPage';
import InvoiceExceptionListPage from '@/features/procurement/pages/InvoiceExceptionListPage';
import ProcurementReportPage from '@/features/procurement/pages/ProcurementReportPage';
import ProcurementSettingsPage from '@/features/procurement/pages/ProcurementSettingsPage';

/**
 * Modul Pengadaan & Vendor.
 *
 * Otorisasi sebenarnya ditegakkan backend (`procurement.*`); guard di sini
 * hanya menyembunyikan halaman yang pasti ditolak agar pengguna tidak menabrak
 * dinding. Dua hal TIDAK diserahkan ke frontend: angka keuangan (tidak dikirim
 * bila tanpa izin) dan keputusan selisih pencocokan (selalu lewat izin
 * `procurement.match_invoice`).
 */
const guard = (permission: string | string[], element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'procurement', element: guard('procurement.view', <ProcurementDashboardPage />) },
  { path: 'procurement/dashboard', element: guard('procurement.view', <ProcurementDashboardPage />) },

  { path: 'procurement/vendors', element: guard('procurement.view', <VendorListPage />) },
  { path: 'procurement/vendors/:vendorId', element: guard('procurement.view', <VendorDetailPage />) },

  { path: 'procurement/catalog', element: guard('procurement.view', <CatalogPage />) },

  { path: 'procurement/purchase-requests', element: guard('procurement.view', <PurchaseRequestListPage />) },
  { path: 'procurement/purchase-requests/:requestId', element: guard('procurement.view', <PurchaseRequestDetailPage />) },

  { path: 'procurement/rfqs', element: guard('procurement.view', <RfqListPage />) },
  { path: 'procurement/rfqs/:rfqId', element: guard('procurement.view', <RfqDetailPage />) },

  { path: 'procurement/purchase-orders', element: guard('procurement.view', <PurchaseOrderListPage />) },
  { path: 'procurement/purchase-orders/:orderId', element: guard('procurement.view', <PurchaseOrderDetailPage />) },

  { path: 'procurement/receiving', element: guard('procurement.view', <ReceivingListPage />) },

  { path: 'procurement/invoices', element: guard('procurement.view', <InvoiceListPage />) },
  { path: 'procurement/invoices/:invoiceId', element: guard('procurement.view', <InvoiceDetailPage />) },
  { path: 'procurement/invoice-exceptions', element: guard('procurement.view', <InvoiceExceptionListPage />) },

  { path: 'procurement/reports', element: guard('procurement.view_reports', <ProcurementReportPage />) },
  { path: 'procurement/settings', element: guard('procurement.manage_settings', <ProcurementSettingsPage />) },
];

export const procurementMenu: MenuChild[] = [
  { label: 'Dasbor Pengadaan', to: '/procurement/dashboard', permission: 'procurement.view' },
  { label: 'Vendor', to: '/procurement/vendors', permission: 'procurement.view' },
  { label: 'Katalog', to: '/procurement/catalog', permission: 'procurement.view' },
  { label: 'Permintaan (PR)', to: '/procurement/purchase-requests', permission: 'procurement.view' },
  { label: 'RFQ & Penawaran', to: '/procurement/rfqs', permission: 'procurement.view' },
  { label: 'Purchase Order', to: '/procurement/purchase-orders', permission: 'procurement.view' },
  { label: 'Penerimaan', to: '/procurement/receiving', permission: 'procurement.view' },
  { label: 'Invoice Vendor', to: '/procurement/invoices', permission: 'procurement.view' },
  { label: 'Pengecualian Invoice', to: '/procurement/invoice-exceptions', permission: 'procurement.view' },
  { label: 'Laporan Pengadaan', to: '/procurement/reports', permission: 'procurement.view_reports' },
  { label: 'Pengaturan', to: '/procurement/settings', permission: 'procurement.manage_settings' },
];
