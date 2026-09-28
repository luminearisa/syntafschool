import { useState, type FormEvent } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import {
  BookOpen,
  CalendarDays,
  GraduationCap,
  LockKeyhole,
  Mail,
  ShieldCheck,
  UsersRound,
} from 'lucide-react';
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
  const setAuth = useAuthStore((state) => state.setAuth);

  // Dev-only convenience; never prefill a known administrator credential in production.
  const [email, setEmail] = useState(import.meta.env.DEV ? 'admin@syntaf.sch.id' : '');
  const [password, setPassword] = useState(import.meta.env.DEV ? 'password' : '');

  const from = (location.state as LocationState)?.from?.pathname ?? '/';

  const loginMutation = useMutation({
    mutationFn: () => authApi.login({ email, password }),
    onSuccess: (data) => {
      setAuth(data.token, data.user);
      toast.success(`Selamat datang, ${data.user.name}`);
      navigate(from, { replace: true });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    if (!email || !password) {
      toast.error('Email dan password wajib diisi');
      return;
    }
    loginMutation.mutate();
  };

  const errors = getValidationErrors(loginMutation.error);

  return (
    <main className="login-page">
      <section className="login-brand-panel" aria-label="Tentang Syntaf School">
        <div className="login-brand-lockup">
          <span className="login-brand-mark" aria-hidden="true">
            <GraduationCap className="h-5 w-5" strokeWidth={1.8} />
          </span>
          <span>
            <span className="login-brand-name block">Syntaf School</span>
            <span className="login-brand-tagline block">ERP sekolah</span>
          </span>
        </div>

        <div className="login-brand-copy">
          <p className="login-eyebrow">Portal manajemen sekolah</p>
          <h1>
            Satu ruang kerja.<br />
            <span>Banyak kemajuan.</span>
          </h1>
          <p>
            Kelola kegiatan sekolah dengan lebih tertata—dari akademik dan
            kesiswaan hingga layanan operasional, semuanya terhubung dalam satu
            sistem.
          </p>
          <div className="login-feature-list" aria-label="Fitur utama">
            <span className="login-feature-chip"><BookOpen aria-hidden="true" /> Akademik</span>
            <span className="login-feature-chip"><UsersRound aria-hidden="true" /> Kesiswaan</span>
            <span className="login-feature-chip"><CalendarDays aria-hidden="true" /> Operasional</span>
          </div>
        </div>

        <p className="login-brand-footer">Syntaf School ERP · Sistem informasi sekolah</p>
      </section>

      <section className="login-form-panel" aria-label="Masuk ke Syntaf School">
        <div className="login-form-wrap">
          <div className="login-form-heading">
            <p className="login-eyebrow">Selamat datang kembali</p>
            <h2>Masuk ke akun Anda</h2>
            <p>Gunakan email dan kata sandi yang terdaftar untuk melanjutkan.</p>
          </div>

          <form onSubmit={handleSubmit} className="login-form">
            <div>
              <Label htmlFor="login-email" required>Email</Label>
              <div className="relative">
                <Mail className="login-control-icon" aria-hidden="true" />
                <Input
                  id="login-email"
                  type="email"
                  placeholder="nama@sekolah.sch.id"
                  value={email}
                  error={errors?.email?.[0]}
                  aria-invalid={Boolean(errors?.email?.[0])}
                  onChange={(event) => setEmail(event.target.value)}
                  autoComplete="username"
                  required
                />
              </div>
            </div>

            <div>
              <Label htmlFor="login-password" required>Kata sandi</Label>
              <div className="relative">
                <LockKeyhole className="login-control-icon" aria-hidden="true" />
                <Input
                  id="login-password"
                  type="password"
                  placeholder="Masukkan kata sandi"
                  value={password}
                  error={errors?.password?.[0]}
                  aria-invalid={Boolean(errors?.password?.[0])}
                  onChange={(event) => setPassword(event.target.value)}
                  autoComplete="current-password"
                  required
                />
              </div>
            </div>

            <Button
              type="submit"
              className="login-submit w-full"
              loading={loginMutation.isPending}
            >
              Masuk ke akun
            </Button>
          </form>

          {import.meta.env.DEV && (
            <p className="login-demo-note">
              Mode pengembangan · Formulir terisi otomatis untuk pengujian lokal.
            </p>
          )}

          <div className="login-form-footer">
            <span>© {new Date().getFullYear()} Syntaf School ERP</span>
            <span className="login-secure-note"><ShieldCheck aria-hidden="true" /> Akses aman</span>
          </div>
        </div>
      </section>
    </main>
  );
}
