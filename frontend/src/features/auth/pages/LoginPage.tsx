import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import {
  ArrowRight,
  ArrowUpRight,
  BookOpen,
  CalendarCheck,
  CircleCheck,
  Eye,
  EyeOff,
  GraduationCap,
  LockKeyhole,
  Mail,
  ShieldCheck,
  UsersRound,
  Wallet,
} from 'lucide-react';
import { toast } from 'sonner';
import { authApi } from '../api/authApi';
import { useAuthStore } from '@/stores/authStore';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import './login.css';

interface LocationState {
  from?: { pathname: string };
}

const BARS = [46, 62, 54, 78, 68, 88, 74];
const DAYS = ['S', 'S', 'R', 'K', 'J', 'S', 'M'];

export default function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const setAuth = useAuthStore((state) => state.setAuth);

  // Dev-only convenience; never prefill a known administrator credential in production.
  const [email, setEmail] = useState(import.meta.env.DEV ? 'admin@syntaf.sch.id' : '');
  const [password, setPassword] = useState(import.meta.env.DEV ? 'password' : '');
  const [showPassword, setShowPassword] = useState(false);
  const [capsLock, setCapsLock] = useState(false);

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
    <main className="auth-page">
      {/* ───────── Panel brand ───────── */}
      <section className="auth-brand" aria-label="Tentang Syntaf School">
        <div className="auth-brand-top">
          <span className="auth-logo" aria-hidden="true">
            <GraduationCap strokeWidth={1.8} />
          </span>
          <span>
            <span className="auth-logo-name">Syntaf School</span>
            <span className="auth-logo-sub">ERP Sekolah</span>
          </span>
        </div>

        <div className="auth-brand-body">
          <p className="auth-eyebrow auth-eyebrow-light">
            <span className="auth-eyebrow-dot" /> Portal manajemen sekolah
          </p>
          <h1>
            Satu ruang kerja.
            <span>Banyak kemajuan.</span>
          </h1>
          <p className="auth-lead">
            Kelola akademik, kesiswaan, hingga layanan operasional dalam satu
            sistem yang terhubung dan mudah dipantau.
          </p>

          {/* Pratinjau dasbor (ilustrasi) */}
          <div className="auth-preview" aria-hidden="true">
            <div className="auth-card auth-card-main">
              <div className="auth-card-head">
                <span>Ringkasan minggu ini</span>
                <span className="auth-pill">Pratinjau</span>
              </div>
              <div className="auth-metrics">
                <div>
                  <strong>96%</strong>
                  <span>Kehadiran</span>
                </div>
                <div>
                  <strong>24</strong>
                  <span>Kelas aktif</span>
                </div>
                <div>
                  <strong>87</strong>
                  <span>Rata-rata nilai</span>
                </div>
              </div>
              <div className="auth-chart">
                {BARS.map((h, i) => (
                  <div key={i} className="auth-bar-col">
                    <div
                      className={`auth-bar${i === 5 ? ' is-active' : ''}`}
                      style={{ height: `${h}%`, animationDelay: `${0.5 + i * 0.07}s` }}
                    />
                    <span>{DAYS[i]}</span>
                  </div>
                ))}
              </div>
            </div>

            <div className="auth-card auth-card-float auth-card-a">
              <span className="auth-float-icon"><CircleCheck /></span>
              <div>
                <strong>Absensi kelas VII-A</strong>
                <span>Tercatat 07.12 WIB</span>
              </div>
            </div>

            <div className="auth-card auth-card-float auth-card-b">
              <span className="auth-float-icon is-gold"><Wallet /></span>
              <div>
                <strong>Pembayaran SPP</strong>
                <div className="auth-progress"><i /></div>
              </div>
            </div>
          </div>
        </div>

        <div className="auth-brand-bottom">
          <ul className="auth-chips" aria-label="Modul utama">
            <li><BookOpen aria-hidden="true" /> Akademik</li>
            <li><UsersRound aria-hidden="true" /> Kesiswaan</li>
            <li><CalendarCheck aria-hidden="true" /> Operasional</li>
          </ul>
          <p>Syntaf School ERP · Sistem informasi sekolah</p>
        </div>
      </section>

      {/* ───────── Panel form ───────── */}
      <section className="auth-form-side" aria-label="Masuk ke Syntaf School">
        <div className="auth-form-wrap">
          <div className="auth-mobile-logo" aria-hidden="true">
            <span className="auth-logo"><GraduationCap strokeWidth={1.8} /></span>
            <span className="auth-logo-name auth-logo-name-dark">Syntaf School</span>
          </div>

          <header className="auth-heading">
            <p className="auth-eyebrow">Selamat datang kembali</p>
            <h2>Masuk ke akun Anda</h2>
            <p>Gunakan email dan kata sandi yang terdaftar untuk melanjutkan.</p>
          </header>

          <form onSubmit={handleSubmit} className="auth-form" noValidate>
            <div>
              <Label htmlFor="login-email" required>Email</Label>
              <div className="relative">
                <Mail className="auth-field-icon" aria-hidden="true" />
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
                <LockKeyhole className="auth-field-icon" aria-hidden="true" />
                <Input
                  id="login-password"
                  type={showPassword ? 'text' : 'password'}
                  placeholder="Masukkan kata sandi"
                  value={password}
                  error={errors?.password?.[0]}
                  aria-invalid={Boolean(errors?.password?.[0])}
                  onChange={(event) => setPassword(event.target.value)}
                  onKeyUp={(event) => setCapsLock(event.getModifierState('CapsLock'))}
                  onBlur={() => setCapsLock(false)}
                  autoComplete="current-password"
                  className="auth-password-input"
                  required
                />
                <button
                  type="button"
                  className="auth-toggle"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
                  aria-pressed={showPassword}
                >
                  {showPassword ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
                </button>
              </div>
              {capsLock && (
                <p className="auth-hint" role="status">Caps Lock sedang aktif.</p>
              )}
            </div>

            <button
              type="submit"
              className="auth-submit"
              disabled={loginMutation.isPending}
            >
              {loginMutation.isPending ? (
                <>
                  <span className="auth-spinner" aria-hidden="true" /> Memproses…
                </>
              ) : (
                <>
                  Masuk ke akun <ArrowRight aria-hidden="true" />
                </>
              )}
            </button>
          </form>

          {import.meta.env.DEV && (
            <p className="auth-dev-note">
              Mode pengembangan · Formulir terisi otomatis untuk pengujian lokal.
            </p>
          )}

          <div className="auth-divider"><span>atau</span></div>

          <Link to="/ppdb" className="auth-ppdb-link">
            <span>
              <strong>Orang tua calon siswa?</strong>
              <small>Buka portal pendaftaran siswa baru (PPDB)</small>
            </span>
            <ArrowUpRight aria-hidden="true" />
          </Link>

          <footer className="auth-footer">
            <span>© {new Date().getFullYear()} Syntaf School ERP</span>
            <span className="auth-secure"><ShieldCheck aria-hidden="true" /> Koneksi aman</span>
          </footer>
        </div>
      </section>
    </main>
  );
}
