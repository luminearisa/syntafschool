import { useEffect, type ReactNode } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate, Navigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, GraduationCap, Home, LayoutList, LogOut, User, Users } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { useAuthStore } from '@/stores/authStore';
import { cn } from '@/lib/utils';
import { ppdbAuthApi, ppdbPortalApi, ppdbPortalNotificationsApi } from '../../api/ppdbApi';

const NAV = [
  { to: '/ppdb/portal', label: 'Ringkasan', icon: Home, end: true },
  { to: '/ppdb/portal/pendaftaran', label: 'Pendaftaran', icon: LayoutList, end: false },
  { to: '/ppdb/portal/applicants', label: 'Anak saya', icon: Users, end: false },
  { to: '/ppdb/portal/notifikasi', label: 'Notifikasi', icon: Bell, end: false },
  { to: '/ppdb/portal/profil', label: 'Profil', icon: User, end: false },
];

function NavItem({ to, label, icon: Icon, end }: (typeof NAV)[number]) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) =>
        cn(
          'flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg px-3 text-sm font-medium transition-colors',
          isActive ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100',
        )
      }
    >
      <Icon className="h-4 w-4 shrink-0" />
      {label}
    </NavLink>
  );
}

/** Cangkang chromeless portal orang tua: tanpa sidebar & navbar admin. */
export function PpdbPortalLayout() {
  const location = useLocation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const token = useAuthStore((state) => state.token);
  const user = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);
  const logoutStore = useAuthStore((state) => state.logout);

  const currentPath = `${location.pathname}${location.search}`;

  const identityQuery = useQuery({
    queryKey: ['ppdb', 'auth', 'me'],
    queryFn: () => ppdbAuthApi.me(),
    enabled: Boolean(token) && !user,
    staleTime: 300_000,
  });

  useEffect(() => {
    if (identityQuery.data && !user) {
      setUser({
        id: identityQuery.data.id,
        name: identityQuery.data.name,
        email: identityQuery.data.email,
        phone: null,
        username: null,
        avatar_path: null,
        userable_type: null,
        userable_id: null,
        is_active: true,
        last_login_at: null,
        roles: identityQuery.data.roles,
        created_at: null,
        updated_at: null,
      });
    }
  }, [identityQuery.data, setUser, user]);

  const dashboardQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'dashboard'],
    queryFn: () => ppdbPortalApi.dashboard(),
    enabled: Boolean(token),
    staleTime: 30_000,
  });

  const unreadQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'notifications', 'unread'],
    queryFn: () => ppdbPortalNotificationsApi.unreadCount(),
    enabled: Boolean(token),
    refetchInterval: 60_000,
  });

  if (!token) {
    return <Navigate to={`/ppdb/login?next=${encodeURIComponent(currentPath)}`} replace />;
  }

  const periodName = dashboardQuery.data?.period?.public_title || dashboardQuery.data?.period?.name;
  const displayName = user?.name ?? dashboardQuery.data?.parent?.name ?? 'Orang tua';
  const unread = unreadQuery.data ?? dashboardQuery.data?.unread_notifications ?? 0;

  const handleLogout = async () => {
    try {
      await ppdbAuthApi.logout();
    } finally {
      logoutStore();
      queryClient.clear();
      navigate('/ppdb/login', { replace: true });
    }
  };

  let body: ReactNode;
  if (identityQuery.isLoading && !user) {
    body = (
      <div className="flex items-center justify-center gap-2 py-24 text-sm text-slate-500">
        <Spinner /> Memeriksa sesi Anda...
      </div>
    );
  } else if (identityQuery.isError && !user) {
    body = (
      <div className="py-16 text-center text-sm text-slate-600">
        Sesi Anda tidak dapat diperiksa. Silakan masuk kembali.
      </div>
    );
  } else {
    body = <Outlet />;
  }

  return (
    <div className="min-h-screen bg-slate-50 pb-10">
      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
          <Link to="/ppdb/portal" className="flex min-w-0 items-center gap-2">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white">
              <GraduationCap className="h-5 w-5" />
            </span>
            <span className="min-w-0">
              <span className="block truncate text-sm font-semibold text-slate-900">Portal PPDB</span>
              <span className="block truncate text-xs text-slate-500">{periodName ?? 'Penerimaan peserta didik baru'}</span>
            </span>
          </Link>

          <div className="ml-auto flex shrink-0 items-center gap-1">
            <Link
              to="/ppdb/portal/notifikasi"
              aria-label={`Notifikasi, ${unread} belum dibaca`}
              className="relative flex h-11 w-11 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100"
            >
              <Bell className="h-5 w-5" />
              {unread > 0 && (
                <Badge tone="red" className="absolute right-1 top-1 h-4 min-w-4 items-center justify-center px-1 text-[10px]">
                  {unread > 99 ? '99+' : unread}
                </Badge>
              )}
            </Link>
            <div className="hidden min-w-0 text-right sm:block">
              <p className="truncate text-xs font-medium text-slate-700">{displayName}</p>
              {dashboardQuery.data && (
                <p className="truncate text-[11px] text-slate-400">
                  {dashboardQuery.data.parent?.account_status === 'active'
                    ? 'Akun aktif'
                    : 'Akun menunggu tindakan'}
                </p>
              )}
            </div>
            <button
              type="button"
              onClick={handleLogout}
              className="flex h-11 items-center gap-1.5 rounded-lg px-2.5 text-sm text-slate-600 hover:bg-slate-100"
            >
              <LogOut className="h-4 w-4" />
              <span className="hidden sm:inline">Keluar</span>
            </button>
          </div>
        </div>

        <nav className="mx-auto flex max-w-3xl gap-1 overflow-x-auto px-3 pb-2">
          {NAV.map((item) => (
            <NavItem key={item.to} {...item} />
          ))}
        </nav>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-4">{body}</main>
    </div>
  );
}

export default PpdbPortalLayout;
