import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import UksDashboardPage from '@/features/uks/pages/UksDashboardPage';
import UksVisitsPage from '@/features/uks/pages/UksVisitsPage';
import UksVisitDetailPage from '@/features/uks/pages/UksVisitDetailPage';
import UksMedicinesPage from '@/features/uks/pages/UksMedicinesPage';
import UksRecordsPage from '@/features/uks/pages/UksRecordsPage';
import UksIncidentsPage from '@/features/uks/pages/UksIncidentsPage';
import UksLettersPage from '@/features/uks/pages/UksLettersPage';
import UksOfficersPage from '@/features/uks/pages/UksOfficersPage';

/**
 * Modul UKS (Usaha Kesehatan Sekolah).
 *
 * Otorisasi layar ditegakkan backend (`uks.*`); guard di sini hanya
 * menyembunyikan halaman yang pasti ditolak. Baris mana yang terlihat
 * (sekolah / kelas ampuan / anak sendiri / diri sendiri) juga ditegakkan
 * backend lewat `UksAccessService`.
 */
const guard = (permission: string, element: JSX.Element): JSX.Element => (
  <PermissionGuard permission={permission}>{element}</PermissionGuard>
);

export const domainRoutes: RouteObject[] = [
  { path: 'uks', element: guard('uks.view', <UksDashboardPage />) },
  { path: 'uks/visits', element: guard('uks.visit.view', <UksVisitsPage />) },
  { path: 'uks/visits/:visitId', element: guard('uks.visit.view', <UksVisitDetailPage />) },
  { path: 'uks/medicines', element: guard('uks.medicine.view', <UksMedicinesPage />) },
  { path: 'uks/records', element: guard('uks.record.view', <UksRecordsPage />) },
  { path: 'uks/incidents', element: guard('uks.incident.view', <UksIncidentsPage />) },
  { path: 'uks/letters', element: guard('uks.letter.view', <UksLettersPage />) },
  { path: 'uks/officers', element: guard('uks.officer.manage', <UksOfficersPage />) },
];

/** Menu sendiri (grup "UKS" di sidebar), tidak ditempel ke "Akademik". */
export const uksMenu: MenuChild[] = [
  { label: 'Beranda', to: '/uks', permission: 'uks.view' },
  { label: 'Kunjungan', to: '/uks/visits', permission: 'uks.visit.view' },
  { label: 'Rekam Kesehatan', to: '/uks/records', permission: 'uks.record.view' },
  { label: 'Insiden', to: '/uks/incidents', permission: 'uks.incident.view' },
  { label: 'Surat Keterangan', to: '/uks/letters', permission: 'uks.letter.view' },
  { label: 'Obat & Stok', to: '/uks/medicines', permission: 'uks.medicine.view' },
  { label: 'Petugas', to: '/uks/officers', permission: 'uks.officer.manage' },
];
