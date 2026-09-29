import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import SupervisiSupervisorsPage from '@/features/supervisi/pages/SupervisiSupervisorsPage';
import SupervisiInstrumentsPage from '@/features/supervisi/pages/SupervisiInstrumentsPage';
import SupervisiInstrumentBuilderPage from '@/features/supervisi/pages/SupervisiInstrumentBuilderPage';
import SupervisiVisitsPage from '@/features/supervisi/pages/SupervisiVisitsPage';
import SupervisiVisitDetailPage from '@/features/supervisi/pages/SupervisiVisitDetailPage';

/**
 * Modul Supervisi.
 *
 * Fase 1 — pengaturan: siapa yang boleh menilai (registry supervisor) dan
 * penyusunan instrumen (skala rubrik maupun entri angka).
 * Fase 2 — pelaksanaan: daftar kegiatan, pengisian instrumen di kelas,
 * wawancara, temuan, finalisasi, tanda tangan, dan keberatan.
 *
 * Otorisasi tetap ditegakkan backend (`supervisi.*`); guard di sini hanya
 * menyembunyikan halaman yang pasti ditolak.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'supervisi',
    element: (
      <PermissionGuard permission="supervisi.view">
        <SupervisiSupervisorsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/instruments',
    element: (
      <PermissionGuard permission="supervisi.instrument.view">
        <SupervisiInstrumentsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/instruments/:instrumentId/versions/:versionId',
    element: (
      <PermissionGuard permission="supervisi.instrument.view">
        <SupervisiInstrumentBuilderPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/visits',
    element: (
      <PermissionGuard permission="supervisi.visit.view">
        <SupervisiVisitsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'supervisi/visits/:visitId',
    element: (
      <PermissionGuard permission="supervisi.visit.view">
        <SupervisiVisitDetailPage />
      </PermissionGuard>
    ),
  },
];

export const domainMenu: MenuChild[] = [
  { label: 'Kegiatan Supervisi', to: '/supervisi/visits', permission: 'supervisi.visit.view' },
  { label: 'Pengaturan Supervisor', to: '/supervisi', permission: 'supervisi.view' },
  { label: 'Instrumen Supervisi', to: '/supervisi/instruments', permission: 'supervisi.instrument.view' },
];
