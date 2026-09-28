import { useEffect, useRef, useState } from 'react';
import {
  CalendarDays,
  ChevronDown,
  ChevronRight,
  LogOut,
  Menu,
} from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { useAuthStore } from '@/stores/authStore';
import { authApi } from '@/features/auth/api/authApi';
import { useUiStore } from '@/stores/uiStore';

function initials(name?: string | null): string {
  const parts = name?.trim().split(/\s+/).filter(Boolean) ?? [];
  if (parts.length === 0) return 'SS';
  return parts.slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function todayLabel(): string {
  return new Intl.DateTimeFormat('id-ID', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date());
}

export function Header({ title, section }: { title?: string; section?: string }) {
  const navigate = useNavigate();
  const user = useAuthStore((state) => state.user);
  const logout = useAuthStore((state) => state.logout);
  const sidebarOpen = useUiStore((state) => state.sidebarOpen);
  const setSidebarOpen = useUiStore((state) => state.setSidebarOpen);
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!menuOpen) return;
    const closeOnOutsideClick = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setMenuOpen(false);
      }
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMenuOpen(false);
    };
    document.addEventListener('mousedown', closeOnOutsideClick);
    window.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('mousedown', closeOnOutsideClick);
      window.removeEventListener('keydown', closeOnEscape);
    };
  }, [menuOpen]);

  const handleLogout = async () => {
    setMenuOpen(false);
    try {
      await authApi.logout();
    } catch {
      // Clear local session even if the API is unavailable.
    }
    logout();
    toast.success('Berhasil keluar');
    navigate('/login');
  };

  return (
    <header className="app-topbar">
      <div className="topbar-leading">
        <button
          type="button"
          aria-label={sidebarOpen ? 'Tutup navigasi' : 'Buka navigasi'}
          aria-expanded={sidebarOpen}
          onClick={() => setSidebarOpen(!sidebarOpen)}
          className="mobile-nav-toggle lg:hidden"
        >
          <Menu aria-hidden="true" />
        </button>
        <nav className="topbar-breadcrumb" aria-label="Breadcrumb">
          <span className="topbar-breadcrumb-home">Syntaf</span>
          <ChevronRight className="topbar-breadcrumb-divider" aria-hidden="true" />
          {section && section !== title && (
            <>
              <span className="topbar-breadcrumb-section">{section}</span>
              <ChevronRight className="topbar-breadcrumb-divider" aria-hidden="true" />
            </>
          )}
          <span className="topbar-breadcrumb-current" aria-current="page">
            {title ?? 'Dashboard'}
          </span>
        </nav>
      </div>

      <div className="topbar-actions">
        <div className="topbar-date" aria-label={`Tanggal ${todayLabel()}`}>
          <CalendarDays aria-hidden="true" />
          <span>{todayLabel()}</span>
        </div>
        <div className="topbar-divider" aria-hidden="true" />

        <div className="topbar-user-wrap" ref={menuRef}>
          <button
            type="button"
            aria-label="Buka menu akun"
            aria-expanded={menuOpen}
            aria-haspopup="menu"
            onClick={() => setMenuOpen((open) => !open)}
            className="topbar-user-trigger"
          >
            <span className="topbar-avatar" aria-hidden="true">{initials(user?.name)}</span>
            <span className="topbar-user-copy">
              <span className="topbar-user-name">{user?.name ?? 'Pengguna'}</span>
              <span className="topbar-user-role">{user?.roles?.[0] ?? 'Akun sekolah'}</span>
            </span>
            <ChevronDown className="topbar-user-chevron" aria-hidden="true" />
          </button>

          {menuOpen && (
            <div className="topbar-menu" role="menu" aria-label="Menu akun">
              <div className="topbar-menu-account">
                <p className="topbar-menu-account-name">{user?.name ?? 'Pengguna'}</p>
                <p className="topbar-menu-account-email">{user?.email ?? 'Akun Syntaf School'}</p>
              </div>
              <div className="topbar-menu-separator" />
              <button type="button" role="menuitem" onClick={handleLogout} className="topbar-menu-logout">
                <LogOut aria-hidden="true" />
                <span>Keluar dari akun</span>
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
