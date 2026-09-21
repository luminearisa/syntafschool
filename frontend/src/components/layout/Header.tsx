import { useState } from 'react';
import { LogOut, Menu, UserCircle2 } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { useAuthStore } from '@/stores/authStore';
import { authApi } from '@/features/auth/api/authApi';
import { useUiStore } from '@/stores/uiStore';
import { Button } from '@/components/ui/Button';

export function Header({ title }: { title?: string }) {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);
  const toggleSidebar = useUiStore((s) => s.toggleSidebar);
  const [menuOpen, setMenuOpen] = useState(false);

  const handleLogout = async () => {
    try {
      await authApi.logout();
    } catch {
      /* abaikan */
    }
    logout();
    toast.success('Berhasil keluar');
    navigate('/login');
  };

  return (
    <header className="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
      <div className="flex items-center gap-3">
        <button
          onClick={toggleSidebar}
          className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
        >
          <Menu className="h-5 w-5" />
        </button>
        <h1 className="text-lg font-semibold text-slate-800">
          {title ?? 'Dashboard'}
        </h1>
      </div>

      <div className="relative">
        <button
          onClick={() => setMenuOpen((o) => !o)}
          className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-100"
        >
          <UserCircle2 className="h-7 w-7 text-slate-400" />
          <div className="hidden text-left leading-tight sm:block">
            <p className="text-sm font-medium text-slate-800">{user?.name}</p>
            <p className="text-[11px] capitalize text-slate-400">
              {user?.roles?.[0] ?? 'pengguna'}
            </p>
          </div>
        </button>

        {menuOpen && (
          <div className="absolute right-0 z-10 mt-1 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
            <div className="border-b border-slate-100 px-3 py-2">
              <p className="truncate text-sm font-medium text-slate-700">
                {user?.email}
              </p>
            </div>
            <Button
              variant="ghost"
              className="w-full justify-start rounded-none text-red-600 hover:bg-red-50"
              onClick={handleLogout}
            >
              <LogOut className="h-4 w-4" />
              Keluar
            </Button>
          </div>
        )}
      </div>
    </header>
  );
}
