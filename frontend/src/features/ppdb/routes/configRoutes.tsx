import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { PpdbPeriodsPage } from '../pages/config/PpdbPeriodsPage';
import { PpdbWavesPage } from '../pages/config/PpdbWavesPage';
import { PpdbProgramsPage } from '../pages/config/PpdbProgramsPage';
import { PpdbFormBuilderPage } from '../pages/config/PpdbFormBuilderPage';
import { PpdbQuestionsPage } from '../pages/config/PpdbQuestionsPage';
import { PpdbDocumentRequirementsPage } from '../pages/config/PpdbDocumentRequirementsPage';
import { PpdbFeesPage } from '../pages/config/PpdbFeesPage';

/**
 * Rute konfigurasi PPDB.
 *
 * Path relatif agar dapat ditempel apa adanya ke `children` `AppLayout` oleh
 * `src/routes/domains`; hasil akhirnya `/ppdb/periods`, `/ppdb/waves`, dst.
 *
 * Halaman ini SENGAJA tidak diekspor sebagai `domainRoutes`: penggabungan
 * dilakukan pusat agar tidak bertabrakan dengan rute PPDB lain (pendaftaran,
 * seleksi, portal) yang dibangun paralel.
 */
export const ppdbConfigRoutes: RouteObject[] = [
  {
    path: 'ppdb/periods',
    element: (
      <PermissionGuard permission="ppdb.period.manage">
        <PpdbPeriodsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/waves',
    element: (
      <PermissionGuard permission="ppdb.wave.manage">
        <PpdbWavesPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/programs',
    element: (
      <PermissionGuard permission="ppdb.program.manage">
        <PpdbProgramsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/form-builder',
    element: (
      <PermissionGuard permission="ppdb.form.manage">
        <PpdbFormBuilderPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/questions',
    element: (
      <PermissionGuard permission="ppdb.question.manage">
        <PpdbQuestionsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/document-requirements',
    element: (
      <PermissionGuard permission="ppdb.document.manage">
        <PpdbDocumentRequirementsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'ppdb/fees',
    element: (
      <PermissionGuard permission="ppdb.fee.manage">
        <PpdbFeesPage />
      </PermissionGuard>
    ),
  },
];

/** Anak menu grup PPDB — label Indonesia, path absolut untuk sidebar. */
export const ppdbConfigMenu: MenuChild[] = [
  { label: 'Periode', to: '/ppdb/periods', permission: 'ppdb.period.manage' },
  { label: 'Gelombang', to: '/ppdb/waves', permission: 'ppdb.wave.manage' },
  { label: 'Program', to: '/ppdb/programs', permission: 'ppdb.program.manage' },
  { label: 'Pembangun Formulir', to: '/ppdb/form-builder', permission: 'ppdb.form.manage' },
  { label: 'Pertanyaan', to: '/ppdb/questions', permission: 'ppdb.question.manage' },
  {
    label: 'Persyaratan Dokumen',
    to: '/ppdb/document-requirements',
    permission: 'ppdb.document.manage',
  },
  { label: 'Biaya Pendaftaran', to: '/ppdb/fees', permission: 'ppdb.fee.manage' },
];
