import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { AlertCircle, GraduationCap, Lock, Mail, ShieldAlert } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { ppdbAuthApi } from '../../api/ppdbApi';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import type { LoginResult } from '@/types';

const ACCOUNT_BLOCKED_RE = /verifikasi|belum.*aktif|diblokir|blokir|nonaktif|ditolak|blocked|pending/i;

/** Halaman masuk khusus portal orang tua — terpisah dari login admin. */
export function PpdbLoginPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const setAuth = useAuthStore((state) => state.setAuth);
  const token = useAuthStore((state) => state.token);

  const next = params.get('next') || '/ppdb/portal';
  const safeNext = next.startsWith('/ppdb') ? next : '/ppdb/portal';

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [accountNotice, setAccountNotice] = useState<string | null>(null);

  const loginMutation = useMutation({
    mutationFn: () => ppdbAuthApi.login({ email: email.trim(), password, device_name: 'ppdb-portal' }),
    onSuccess: (data: LoginResult) => {
      setAuth(data.token, data.user);
      toast.success(`Selamat datang, ${data.user.name}`);
      navigate(safeNext, { replace: true });
    },
    onError: (error) => {
      const message = getErrorMessage(error);
      if (ACCOUNT_BLOCKED_RE.test(message)) {
        setAccountNotice(message);
      } else {
        setAccountNotice(null);
        toast.error(message);
      }
    },
  });

  const serverErrors = getValidationErrors(loginMutation.error);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!email.trim() || !password) {
      toast.error('Email dan password wajib diisi.');
      return;
    }
    setAccountNotice(null);
    loginMutation.mutate();
  };

  return (
    <div className="flex min-h-screen flex-col bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-md items-center gap-2 px-4 py-3">
          <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-600 text-white">
            <GraduationCap className="h-5 w-5" />
          </span>
          <div>
            <p className="text-sm font-semibold text-slate-900">Portal PPDB Orang Tua</p>
            <p className="text-xs text-slate-500">Pantau pendaftaran anak Anda</p>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-md flex-1 px-4 py-6">
        {token ? (
          <Card className="p-5 text-center">
            <p className="text-sm font-semibold text-slate-900">Anda sudah masuk</p>
            <p className="mt-1 text-xs text-slate-500">
              Lanjutkan ke portal, atau keluar lebih dahulu bila ini bukan perangkat Anda.
            </p>
            <div className="mt-4 flex flex-col gap-2">
              <Link to={safeNext}>
                <Button size="lg" className="w-full">
                  Buka portal saya
                </Button>
              </Link>
              <Link to="/ppdb">
                <Button size="lg" variant="outline" className="w-full">
                  Lihat informasi PPDB
                </Button>
              </Link>
            </div>
          </Card>
        ) : (
          <Card className="p-5">
            <h1 className="text-lg font-semibold text-slate-900">Masuk ke akun Anda</h1>
            <p className="mt-1 text-sm text-slate-500">
              Gunakan email dan password yang Anda daftarkan. Akun orang tua hanya berlaku di portal ini.
            </p>

            {accountNotice && (
              <div className="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
                <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                <span>
                  {accountNotice}
                  <span className="mt-1 block">
                    Panitia memverifikasi akun pada jam kerja. Anda dapat menghubungi kontak panitia pada
                    halaman informasi periode.
                  </span>
                  <Link to="/ppdb" className="mt-1.5 block font-medium text-amber-900 underline">
                    Lihat halaman informasi PPDB
                  </Link>
                </span>
              </div>
            )}

            <form onSubmit={handleSubmit} className="mt-4 space-y-4" noValidate>
              {loginMutation.isError && !serverErrors && !accountNotice && (
                <p className="flex items-start gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                  <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                  {getErrorMessage(loginMutation.error)}
                </p>
              )}

              <div>
                <Label htmlFor="login-email" required>
                  Email
                </Label>
                <div className="relative">
                  <Mail className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <Input
                    id="login-email"
                    type="email"
                    className="pl-9"
                    value={email}
                    placeholder="nama@email.com"
                    error={serverErrors?.email?.[0]}
                    autoComplete="email"
                    onChange={(e) => setEmail(e.target.value)}
                  />
                </div>
                {serverErrors?.email?.[0] && (
                  <p className="mt-1 text-xs text-red-500">{serverErrors.email[0]}</p>
                )}
              </div>

              <div>
                <Label htmlFor="login-password" required>
                  Password
                </Label>
                <div className="relative">
                  <Lock className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <Input
                    id="login-password"
                    type="password"
                    className="pl-9"
                    value={password}
                    placeholder="••••••••"
                    error={serverErrors?.password?.[0]}
                    autoComplete="current-password"
                    onChange={(e) => setPassword(e.target.value)}
                  />
                </div>
                {serverErrors?.password?.[0] && (
                  <p className="mt-1 text-xs text-red-500">{serverErrors.password[0]}</p>
                )}
                <p className="mt-1 text-xs text-slate-500">
                  Lupa password? Hubungi panitia PPDB untuk reset melalui email terdaftar.
                </p>
              </div>

              <Button type="submit" size="lg" className="w-full" loading={loginMutation.isPending}>
                Masuk
              </Button>
            </form>

            <div className="mt-5 space-y-2 border-t border-slate-100 pt-4 text-sm">
              <p className="text-slate-600">
                Belum punya akun?{' '}
                <Link to={`/ppdb/register?next=${encodeURIComponent(safeNext)}`} className="font-medium text-brand-600 hover:underline">
                  Buat akun orang tua
                </Link>
              </p>
              <p className="text-slate-600">
                <Link to="/ppdb" className="font-medium text-brand-600 hover:underline">
                  Kembali ke informasi pendaftaran
                </Link>
              </p>
            </div>
          </Card>
        )}
      </main>
    </div>
  );
}

export default PpdbLoginPage;
