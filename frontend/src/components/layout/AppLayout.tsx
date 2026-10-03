import { useEffect, type ReactNode } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Header } from './Header';
import { useUiStore } from '@/stores/uiStore';
import { getResourceDef } from '@/constants/masterData';
import { MENU_ITEMS } from '@/constants/menu';

type PageContext = { title: string; section: string };

function humanizeSegment(segment: string): string {
  return decodeURIComponent(segment)
    .replace(/[-_]+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

/** Resolve the visible breadcrumb from the same route metadata used by navigation. */
function usePageContext(): PageContext {
  const { pathname } = useLocation();
  const normalizedPath = pathname.replace(/\/+$/, '') || '/';

  if (normalizedPath === '/') return { title: 'Dashboard', section: 'Beranda' };

  const masterMatch = normalizedPath.match(/^\/master\/([^/]+)/);
  if (masterMatch) {
    const def = getResourceDef(masterMatch[1]);
    if (def) return { title: def.labelPlural, section: 'Master Data' };
  }

  let match: { title: string; section: string; pathLength: number } | null = null;
  for (const item of MENU_ITEMS) {
    if (item.to && (normalizedPath === item.to || normalizedPath.startsWith(`${item.to}/`))) {
      if (!match || item.to.length > match.pathLength) {
        match = { title: item.label, section: 'Ruang kerja', pathLength: item.to.length };
      }
    }

    for (const child of item.children ?? []) {
      if (normalizedPath === child.to || normalizedPath.startsWith(`${child.to}/`)) {
        if (!match || child.to.length > match.pathLength) {
          match = { title: child.label, section: item.label, pathLength: child.to.length };
        }
      }
    }
  }

  if (match) return { title: match.title, section: match.section };

  const segments = normalizedPath.split('/').filter(Boolean);
  const readable = segments.filter((segment) => !/^\d+$/.test(segment));
  const title = readable.length ? humanizeSegment(readable[readable.length - 1]) : 'Halaman';
  const section = readable.length > 1 ? humanizeSegment(readable[0]) : 'Ruang kerja';
  return { title, section };
}

export function AppLayout({ children }: { children?: ReactNode } = {}) {
  const sidebarOpen = useUiStore((state) => state.sidebarOpen);
  const setSidebarOpen = useUiStore((state) => state.setSidebarOpen);
  const location = useLocation();
  const { title, section } = usePageContext();

  useEffect(() => {
    setSidebarOpen(false);
  }, [location.pathname, setSidebarOpen]);

  useEffect(() => {
    if (!sidebarOpen) return;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setSidebarOpen(false);
    };
    window.addEventListener('keydown', closeOnEscape);
    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [sidebarOpen, setSidebarOpen]);

  return (
    <div className="app-shell">
      <a className="skip-link" href="#main-content">Lewati ke konten utama</a>

      <div className="app-desktop-sidebar">
        <Sidebar />
      </div>

      {sidebarOpen && (
        <div className="mobile-drawer">
          <button
            type="button"
            aria-label="Tutup navigasi"
            className="mobile-drawer-scrim"
            onClick={() => setSidebarOpen(false)}
          />
          <div className="mobile-drawer-panel" role="dialog" aria-modal="true" aria-label="Navigasi aplikasi">
            <Sidebar
              onNavigate={() => setSidebarOpen(false)}
              onClose={() => setSidebarOpen(false)}
            />
          </div>
        </div>
      )}

      <div className="app-workspace">
        <Header title={title} section={section} />
        <main id="main-content" className="app-main" tabIndex={-1}>
          <div className="app-page-content">
            {children ?? <Outlet />}
          </div>
        </main>
      </div>
    </div>
  );
}
