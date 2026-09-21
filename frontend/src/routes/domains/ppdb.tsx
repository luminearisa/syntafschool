import type { RouteObject } from 'react-router-dom';
import type { MenuItem } from '@/constants/menu';
import { ClipboardCheck, SlidersHorizontal } from 'lucide-react';
import { ppdbOpsMenu, ppdbOpsRoutes } from '@/features/ppdb/routes/opsRoutes';
import { ppdbConfigMenu, ppdbConfigRoutes } from '@/features/ppdb/routes/configRoutes';

/**
 * Modul PPDB (Penerimaan Peserta Didik Baru).
 *
 * `domainRoutes` di-glob otomatis oleh `src/routes/domains/index.ts` sehingga
 * halamannya menempel di dalam `AppLayout` — khusus pengguna terautentikasi
 * yang memegang permission `ppdb.*`.
 *
 * Menu PPDB SENGAJA tidak diekspor sebagai `domainMenu`: `domainMenu` digabung
 * ke grup "Akademik", sedangkan PPDB punya grupnya sendiri — operasi panitia
 * dan konfigurasi dipisah karena hak yang dibutuhkan berbeda.
 */
export const domainRoutes: RouteObject[] = [...ppdbOpsRoutes, ...ppdbConfigRoutes];

export const ppdbMenu: MenuItem[] = [
  {
    label: 'PPDB',
    icon: ClipboardCheck,
    permission: 'ppdb.view',
    children: ppdbOpsMenu,
  },
  {
    label: 'Konfigurasi PPDB',
    icon: SlidersHorizontal,
    permission: 'ppdb.period.manage',
    children: ppdbConfigMenu,
  },
];

/**
 * Halaman publik + portal orang tua. Dipasang lewat `domainPublicRoutes` karena
 * keduanya harus berada di luar `ProtectedRoute`/`AppLayout`.
 */
export { ppdbPublicRoutes as domainPublicRoutes } from '@/features/ppdb/routes/portalRoutes';
