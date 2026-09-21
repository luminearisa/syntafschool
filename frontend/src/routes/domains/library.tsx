import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { LibraryDashboardPage } from '@/features/library/pages/LibraryDashboardPage';
import { LibrariesPage } from '@/features/library/pages/LibrariesPage';
import { LibrarySettingsPage } from '@/features/library/pages/LibrarySettingsPage';
import { LibraryLocationsPage } from '@/features/library/pages/LibraryLocationsPage';
import { LibraryOperatingHoursPage } from '@/features/library/pages/LibraryOperatingHoursPage';
import { LibraryClosuresPage } from '@/features/library/pages/LibraryClosuresPage';
import { LibraryAuditLogPage } from '@/features/library/pages/LibraryAuditLogPage';
import { LibraryMastersPage } from '@/features/library/pages/LibraryMastersPage';
import { LibraryCatalogPage } from '@/features/library/pages/LibraryCatalogPage';
import { LibraryCatalogFormPage } from '@/features/library/pages/LibraryCatalogFormPage';
import { LibraryCatalogDetailPage } from '@/features/library/pages/LibraryCatalogDetailPage';
import { LibraryItemsPage } from '@/features/library/pages/LibraryItemsPage';
import { LibraryItemDetailPage } from '@/features/library/pages/LibraryItemDetailPage';
import { LibraryMembersPage } from '@/features/library/pages/LibraryMembersPage';
import { LibraryMemberDetailPage } from '@/features/library/pages/LibraryMemberDetailPage';
import { LibraryMyCardPage } from '@/features/library/pages/LibraryMyCardPage';
import { LibraryCheckoutPage } from '@/features/library/pages/LibraryCheckoutPage';
import { LibraryReturnPage } from '@/features/library/pages/LibraryReturnPage';
import { LibraryLoansPage } from '@/features/library/pages/LibraryLoansPage';
import { LibraryOverduePage } from '@/features/library/pages/LibraryOverduePage';
import { LibraryReservationsPage } from '@/features/library/pages/LibraryReservationsPage';
import { LibraryFinesPage } from '@/features/library/pages/LibraryFinesPage';
import { LibraryAcquisitionsPage } from '@/features/library/pages/LibraryAcquisitionsPage';
import { LibraryAcquisitionDetailPage } from '@/features/library/pages/LibraryAcquisitionDetailPage';
import { LibraryInventoryPage } from '@/features/library/pages/LibraryInventoryPage';
import { LibraryInventoryDetailPage } from '@/features/library/pages/LibraryInventoryDetailPage';
import { LibraryReportsPage } from '@/features/library/pages/LibraryReportsPage';
import { LibraryImportPage } from '@/features/library/pages/LibraryImportPage';
import { LibraryNotificationsPage } from '@/features/library/pages/LibraryNotificationsPage';
import { LibraryLabelPrintPage } from '@/features/library/pages/LibraryLabelPrintPage';

/**
 * Modul Library / Perpustakaan (Step 1 — Fondasi).
 *
 * `domainRoutes` di-glob otomatis oleh `src/routes/domains/index.ts`.
 * `libraryMenu` SENGAJA tidak dinamai `domainMenu`: `domainMenu` dari seluruh
 * domain digabung ke grup "Akademik", sedangkan Perpustakaan punya grup
 * sendiri — lihat `src/constants/menu.ts`.
 *
 * Arsitektur mendukung banyak perpustakaan; halaman yang butuh konteks
 * perpustakaan memakai pemilih perpustakaan.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'library',
    element: (
      <PermissionGuard permission="library.dashboard.view">
        <LibraryDashboardPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/libraries',
    element: (
      <PermissionGuard permission="library.view">
        <LibrariesPage />
      </PermissionGuard>
    ),
  },
  {
    // Antarmuka master terkelompok (18 master) — satu halaman, master dipilih
    // lewat path agar dapat di-bookmark.
    path: 'library/masters',
    element: (
      <PermissionGuard permission="library.master.view">
        <LibraryMastersPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/masters/:master',
    element: (
      <PermissionGuard permission="library.master.view">
        <LibraryMastersPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/settings',
    element: (
      <PermissionGuard permission="library.settings.view">
        <LibrarySettingsPage />
      </PermissionGuard>
    ),
  },

  // ================= KATALOG & KOLEKSI (Fase 3) =================
  {
    path: 'library/catalog',
    element: (
      <PermissionGuard permission="library.catalog.view">
        <LibraryCatalogPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/catalog/new',
    element: (
      <PermissionGuard permission="library.catalog.create">
        <LibraryCatalogFormPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/catalog/:id',
    element: (
      <PermissionGuard permission="library.catalog.view">
        <LibraryCatalogDetailPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/catalog/:id/edit',
    element: (
      <PermissionGuard permission="library.catalog.update">
        <LibraryCatalogFormPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/items',
    element: (
      <PermissionGuard permission="library.item.view">
        <LibraryItemsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/items/:id',
    element: (
      <PermissionGuard permission="library.item.view">
        <LibraryItemDetailPage />
      </PermissionGuard>
    ),
  },

  // ================= KEANGGOTAAN =================
  {
    path: 'library/members',
    element: (
      <PermissionGuard permission="library.member.view">
        <LibraryMembersPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/members/:id',
    element: (
      <PermissionGuard permission="library.member.view">
        <LibraryMemberDetailPage />
      </PermissionGuard>
    ),
  },
  {
    // "Kartu Saya" — setiap pengguna melihat kartunya sendiri (tanpa permission modul).
    path: 'library/my-card',
    element: <LibraryMyCardPage />,
  },

  // ================= SIRKULASI (Fase 4) =================
  {
    path: 'library/circulation/checkout',
    element: (
      <PermissionGuard permission="library.circulation.checkout">
        <LibraryCheckoutPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/circulation/return',
    element: (
      <PermissionGuard permission="library.circulation.return">
        <LibraryReturnPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/circulation/loans',
    element: (
      <PermissionGuard permission="library.circulation.view">
        <LibraryLoansPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/circulation/overdue',
    element: (
      <PermissionGuard permission="library.circulation.view">
        <LibraryOverduePage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/circulation/reservations',
    element: (
      <PermissionGuard permission="library.reservation.view">
        <LibraryReservationsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/fines',
    element: (
      <PermissionGuard permission="library.fine.view">
        <LibraryFinesPage />
      </PermissionGuard>
    ),
  },

  // ================= PENGADAAN & INVENTARIS (Fase 4) =================
  {
    path: 'library/acquisitions',
    element: (
      <PermissionGuard permission="library.acquisition.view">
        <LibraryAcquisitionsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/acquisitions/:id',
    element: (
      <PermissionGuard permission="library.acquisition.view">
        <LibraryAcquisitionDetailPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/inventory',
    element: (
      <PermissionGuard permission="library.inventory.view">
        <LibraryInventoryPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/inventory/:id',
    element: (
      <PermissionGuard permission="library.inventory.view">
        <LibraryInventoryDetailPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/labels',
    element: (
      <PermissionGuard permission="library.item.view">
        <LibraryLabelPrintPage />
      </PermissionGuard>
    ),
  },

  // ================= LAPORAN, IMPOR, NOTIFIKASI (Phase 5) =================
  {
    path: 'library/reports',
    element: (
      <PermissionGuard permission="library.report.view">
        <LibraryReportsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/import',
    element: (
      <PermissionGuard permission="library.report.view">
        <LibraryImportPage />
      </PermissionGuard>
    ),
  },
  {
    // Notifikasi milik pengguna sendiri — tanpa permission modul.
    path: 'library/notifications',
    element: <LibraryNotificationsPage />,
  },
  {
    path: 'library/locations',
    element: (
      <PermissionGuard permission="library.location.view">
        <LibraryLocationsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/operating-hours',
    element: (
      <PermissionGuard permission="library.operating_hours.view">
        <LibraryOperatingHoursPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/closures',
    element: (
      <PermissionGuard permission="library.closure.view">
        <LibraryClosuresPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'library/audit-logs',
    element: (
      <PermissionGuard permission="library.audit.view">
        <LibraryAuditLogPage />
      </PermissionGuard>
    ),
  },
];

export const libraryMenu: MenuChild[] = [
  { label: 'Dashboard', to: '/library', permission: 'library.dashboard.view' },
  // Sirkulasi (Fase 4) — layar cepat lebih dulu agar mudah dijangkau petugas.
  { label: 'Checkout Cepat', to: '/library/circulation/checkout', permission: 'library.circulation.checkout' },
  { label: 'Pengembalian', to: '/library/circulation/return', permission: 'library.circulation.return' },
  { label: 'Peminjaman', to: '/library/circulation/loans', permission: 'library.circulation.view' },
  { label: 'Keterlambatan', to: '/library/circulation/overdue', permission: 'library.circulation.view' },
  { label: 'Reservasi', to: '/library/circulation/reservations', permission: 'library.reservation.view' },
  { label: 'Denda', to: '/library/fines', permission: 'library.fine.view' },
  { label: 'Katalog', to: '/library/catalog', permission: 'library.catalog.view' },
  { label: 'Koleksi', to: '/library/items', permission: 'library.item.view' },
  { label: 'Cetak Label', to: '/library/labels', permission: 'library.item.view' },
  { label: 'Anggota', to: '/library/members', permission: 'library.member.view' },
  { label: 'Pengadaan', to: '/library/acquisitions', permission: 'library.acquisition.view' },
  { label: 'Stock Opname', to: '/library/inventory', permission: 'library.inventory.view' },
  { label: 'Laporan', to: '/library/reports', permission: 'library.report.view' },
  { label: 'Impor Data', to: '/library/import', permission: 'library.report.view' },
  { label: 'Notifikasi', to: '/library/notifications' },
  { label: 'Perpustakaan', to: '/library/libraries', permission: 'library.view' },
  { label: 'Master Data', to: '/library/masters', permission: 'library.master.view' },
  { label: 'Konfigurasi', to: '/library/settings', permission: 'library.settings.view' },
  { label: 'Lokasi', to: '/library/locations', permission: 'library.location.view' },
  {
    label: 'Jam Operasional',
    to: '/library/operating-hours',
    permission: 'library.operating_hours.view',
  },
  { label: 'Penutupan', to: '/library/closures', permission: 'library.closure.view' },
  { label: 'Jejak Audit', to: '/library/audit-logs', permission: 'library.audit.view' },
  // Tanpa permission modul: OPAC memang publik, dan "Kartu Saya" hanya
  // menampilkan kartu milik pengguna sendiri.
  { label: 'Katalog Publik', to: '/library/opac' },
  { label: 'Kartu Saya', to: '/library/my-card' },
];