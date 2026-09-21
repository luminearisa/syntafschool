import { useState, type FormEvent } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { GraduationCap, Lock, Mail } from 'lucide-react';
import { toast } from 'sonner';
import { authApi } from '../api/authApi';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';

interface LocationState {
  from?: { pathname: string };
}

export default function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const setAuth = useAuthStore((s) => s.setAuth);

  // Kredensial demo sengaja tidak ikut ke build produksi: form yang terisi
  // otomatis memberi tahu siapa pun alamat super-admin beserta passwordnya.
  const demoEmail = import.meta.env.DEV ? 'admin@syntaf.sch.id' : '';
  const demoPassword = import.meta.env.DEV ? 'password' : '';

  const [email, setEmail] = useState(demoEmail);
  const [password, setPassword] = useState(demoPassword);

  const from = (location.state as LocationState)?.from?.pathname ?? '/';

  const loginMutation = useMutation({
    mutationFn: () => authApi.login({ email, password }),
    onSuccess: (data) => {
      setAuth(data.token, data.user);
      toast.success(`Selamat datang, ${data.user.name}`);
      navigate(from, { replace: true });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!email || !password) {
      toast.error('Email dan password wajib diisi');
      return;
    }
    loginMutation.mutate();
  };

  const errors = getValidationErrors(loginMutation.error);

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-50 via-slate-50 to-slate-100 p-4">
      <div className="w-full max-w-md">
        <div className="mb-6 flex flex-col items-center">
          <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 text-white shadow-lg">
            <GraduationCap className="h-8 w-8" />
          </div>
          <h1 className="mt-3 text-2xl font-bold text-slate-900">Syntaf School</h1>
          <p className="text-sm text-slate-500">Sistem Informasi Manajemen Sekolah</p>
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="mb-1 text-lg font-semibold text-slate-800">Masuk ke akun Anda</h2>
          <p className="mb-5 text-sm text-slate-500">
            Gunakan email dan password yang terdaftar.
          </p>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <Label required>Email</Label>
              <div className="relative">
                <Mail className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <Input
                  type="email"
                  className="pl-9"
                  placeholder="nama@sekolah.sch.id"
                  value={email}
                  error={errors?.email?.[0]}
                  onChange={(e) => setEmail(e.target.value)}
                  autoComplete="email"
                />
              </div>
            </div>

            <div>
              <Label required>Password</Label>
              <div className="relative">
                <Lock className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <Input
                  type="password"
                  className="pl-9"
                  placeholder="••••••••"
                  value={password}
                  error={errors?.password?.[0]}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                />
              </div>
            </div>

            <Button type="submit" className="w-full" size="lg" loading={loginMutation.isPending}>
              Masuk
            </Button>
          </form>

          {import.meta.env.DEV && (
            <div className="mt-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
              <p className="font-medium text-slate-600">Akun demo:</p>
              <p>admin@syntaf.sch.id / password</p>
            </div>
          )}
        </div>

        <p className="mt-6 text-center text-xs text-slate-400">
          &copy; {new Date().getFullYear()} Syntaf School ERP
        </p>
      </div>
    </div>
  );
}
