import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';

interface DomainModule {
  /** Route yang dipasang di dalam AppLayout (path relatif, tanpa leading slash). */
  domainRoutes?: RouteObject[];
  /** Entri submenu yang ditempel ke grup menu "Akademik". */
  domainMenu?: MenuChild[];
  /** Route yang dipasang di luar ProtectedRoute (halaman publik/portal mandiri). */
  domainPublicRoutes?: RouteObject[];
}

/**
 * Registrasi route & menu per-domain.
 *
 * Setiap file `src/routes/domains/<domain>.tsx` boleh mengekspor:
 *   - `domainRoutes`       — `RouteObject[]` di dalam AppLayout
 *   - `domainMenu`         — `MenuChild[]`
 *   - `domainPublicRoutes` — `RouteObject[]` di luar cangkang aplikasi
 *
 * Pola ini dipakai agar pengerjaan modul secara paralel tidak perlu
 * menyunting file bersama (`routes/index.tsx` dan `constants/menu.ts`),
 * sehingga dua pekerjaan tidak saling menimpa.
 */
const modules = import.meta.glob<DomainModule>('./*.tsx', { eager: true });

export const domainRoutes: RouteObject[] = Object.values(modules).flatMap(
  (mod) => mod.domainRoutes ?? [],
);

export const domainMenu: MenuChild[] = Object.values(modules).flatMap(
  (mod) => mod.domainMenu ?? [],
);

export const domainPublicRoutes: RouteObject[] = Object.values(modules).flatMap(
  (mod) => mod.domainPublicRoutes ?? [],
);
