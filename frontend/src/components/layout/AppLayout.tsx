import { Outlet, useLocation } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Header } from './Header';
import { useUiStore } from '@/stores/uiStore';
import { getResourceDef } from '@/constants/masterData';

/** Tentukan judul halaman dari path */
function usePageTitle(): string {
  const { pathname } = useLocation();
  const masterMatch = pathname.match(/^\/master\/([^/]+)/);
  if (masterMatch) {
    const def = getResourceDef(masterMatch[1]);
    if (def) return def.labelPlural;
  }
  if (pathname === '/') return 'Dashboard';
  return 'Syntaf School';
}

export function AppLayout() {
  const sidebarOpen = useUiStore((s) => s.sidebarOpen);
  const setSidebarOpen = useUiStore((s) => s.setSidebarOpen);
  const title = usePageTitle();

  return (
    <div className="flex h-screen overflow-hidden bg-slate-50">
      {/* Sidebar desktop */}
      <div className="hidden h-full lg:block">
        <Sidebar />
      </div>

      {/* Sidebar mobile (drawer) */}
      {sidebarOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div
            className="absolute inset-0 bg-slate-900/50"
            onClick={() => setSidebarOpen(false)}
          />
          <div className="absolute left-0 top-0 h-full">
            <Sidebar />
          </div>
        </div>
      )}

      <div className="flex flex-1 flex-col overflow-hidden">
        <Header title={title} />
        <main className="flex-1 overflow-y-auto p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
