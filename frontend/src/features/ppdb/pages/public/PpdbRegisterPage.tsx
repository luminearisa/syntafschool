import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { AlertCircle, CheckCircle2, Info, Mail, Lock, Phone, User } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { PublicShell } from '../../components/portal/PublicShell';
import { ppdbAuthApi } from '../../api/ppdbApi';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import type { PpdbRegisterPayload } from '@/types/ppdbPortal';

const PASSWORD_MIN = 8;

/**
 * Pembuatan akun orang tua (publik).
 *
 * `PpdbRegisterPayload` hanya membawa data orang tua — calon siswa dibuat
 * setelah akun jadi, lewat halaman "Anak saya" di portal.
 */
export function PpdbRegisterPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const setAuth = useAuthStore((state) => state.setAuth);
  const next = params.get('next') || '/ppdb/portal';

  const [values, setValues] = useState({
    name: '',
    email: '',
    phone: '',
    address: '',
    password: '',
    password_confirmation: '',
  });
  const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
  const [done, setDone] = useState<{
    email: string;
    statusLabel: string;
    canTransact: boolean;
    nextSteps: string[];
  } | null>(null);

  const registerMutation = useMutation({
    mutationFn: (payload: PpdbRegisterPayload) => ppdbAuthApi.register(payload),
    onSuccess: (result) => {
      // `PpdbRegisterResult` menyimpan status akun di akar respons
      // (`account_status`, `account_status_label`, `can_transact`) — tanpa pembungkus `account`.
      const isActive = result.account_status === 'active';
      const canTransact = result.can_transact;
      setDone({
        email: values.email.trim(),
        statusLabel: result.account_status_label,
        canTransact,
        nextSteps: result.next_steps,
      });

      if (result.token && result.user) {
        // `PpdbRegisterResult.user` memuat id/name/email/phone/roles — sisa kolom diisi
        // netral, lalu diperbarui `ppdbAuthApi.me()` saat portal pertama dibuka.
        setAuth(result.token, {
          id: result.user.id,
          name: result.user.name,
          email: result.user.email,
          username: null,
          phone: result.user.phone,
          avatar_path: null,
          userable_type: null,
          userable_id: null,
          is_active: isActive,
          last_login_at: null,
          created_at: null,
          updated_at: null,
        });
        toast.success(`Selamat datang, ${result.user.name}`);
        navigate(next, { replace: true });
        return;
      }

      toast.success(
        canTransact
          ? `Akun berhasil dibuat (${result.account_status_label}). Silakan lanjutkan melengkapi profil.`
          : `Akun berhasil dibuat (${result.account_status_label}). ${
              result.next_steps[0] ?? 'Panitia akan meninjau akun Anda.'
            }`,
      );
    },
    onError: (error) => {
      setLocalErrors({});
      toast.error(getErrorMessage(error));
    },
  });

  const serverErrors = getValidationErrors(registerMutation.error);
  const err = (key: string) => localErrors[key] ?? serverErrors?.[key]?.[0];

  const set = (key: keyof typeof values, value: string) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setLocalErrors((prev) => {
      const copy = { ...prev };
      delete copy[key];
      return copy;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const errors: Record<string, string> = {};
    if (values.name.trim().length < 3) errors.name = 'Nama lengkap minimal 3 karakter.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(values.email.trim())) errors.email = 'Format email belum benar.';
    if (!/^(\+?62|0)8[1-9][0-9\s-]{6,}$/.test(values.phone.trim().replace(/-/g, ' '))) {
      errors.phone = 'Nomor telepon aktif tidak valid, mis. 081234567890.';
    }
    if (values.password.length < PASSWORD_MIN) errors.password = `Password minimal ${PASSWORD_MIN} karakter.`;
    if (values.password !== values.password_confirmation) {
      errors.password_confirmation = 'Konfirmasi password tidak sama dengan password.';
    }
    if (!values.address.trim()) errors.address = 'Alamat diperlukan untuk data panitia.';

    if (Object.keys(errors).length) {
      setLocalErrors(errors);
      return;
    }

    registerMutation.mutate({
      name: values.name.trim(),
      email: values.email.trim().toLowerCase(),
      phone: values.phone.trim(),
      address: values.address.trim(),
      password: values.password,
      password_confirmation: values.password_confirmation,
    });
  };

  return (
    <PublicShell>
      <div className="mb-4">
        <h1 className="text-xl font-bold text-slate-900">Buat akun orang tua</h1>
        <p className="mt-1 text-sm text-slate-500">
          Satu akun dipakai untuk mendaftarkan semua anak Anda. Cukup daftar sekali, kemudian tambahkan
          anak satu per satu di dalam portal.
        </p>
      </div>

      {done ? (
        <Card className="p-5">
          <div className="flex items-start gap-3">
            <div className="rounded-full bg-green-100 p-2 text-green-600">
              <CheckCircle2 className="h-6 w-6" />
            </div>
            <div className="min-w-0">
              <p className="text-base font-semibold text-slate-900">
                {done.canTransact ? 'Akun Anda sudah aktif' : 'Akun dibuat, menunggu verifikasi panitia'}
              </p>
              <p className="mt-1 text-sm text-slate-600">
                Status akun: <span className="font-medium">{done.statusLabel}</span>, tertaut ke email{' '}
                <span className="font-medium">{done.email}</span>. Berikut langkah selanjutnya:
              </p>
              {done.nextSteps.length > 0 && (
                <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600">
                  {done.nextSteps.map((step) => (
                    <li key={step}>{step}</li>
                  ))}
                </ul>
              )}
              <p className="mt-2 text-xs text-slate-500">
                Simpan email dan password Anda. Bila akses terhambat, hubungi panitia PPDB melalui kanal
                kontak pada halaman informasi periode.
              </p>
              <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                <Link to={`/ppdb/login?next=${encodeURIComponent(next)}`} className="flex-1">
                  <Button size="lg" className="w-full">
                    Masuk ke portal
                  </Button>
                </Link>
                <Link to="/ppdb" className="flex-1">
                  <Button size="lg" variant="outline" className="w-full">
                    Kembali ke informasi PPDB
                  </Button>
                </Link>
              </div>
            </div>
          </div>
        </Card>
      ) : (
        <Card className="p-5">
          <form onSubmit={handleSubmit} className="space-y-4" noValidate>
            {registerMutation.isError && !serverErrors && (
              <p className="flex items-start gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                {getErrorMessage(registerMutation.error)}
              </p>
            )}

            <div>
              <Label htmlFor="reg-name" required>
                Nama lengkap orang tua / wali
              </Label>
              <div className="relative">
                <User className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <Input
                  id="reg-name"
                  className="pl-9"
                  value={values.name}
                  placeholder="Nama sesuai KTP"
                  error={err('name')}
                  autoComplete="name"
                  onChange={(e) => set('name', e.target.value)}
                />
              </div>
              {err('name') && <p className="mt-1 text-xs text-red-500">{err('name')}</p>}
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <Label htmlFor="reg-email" required>
                  Email aktif
                </Label>
                <div className="relative">
                  <Mail className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <Input
                    id="reg-email"
                    type="email"
                    className="pl-9"
                    value={values.email}
                    placeholder="nama@email.com"
                    error={err('email')}
                    autoComplete="email"
                    onChange={(e) => set('email', e.target.value)}
                  />
                </div>
                {err('email') && <p className="mt-1 text-xs text-red-500">{err('email')}</p>}
              </div>
              <div>
                <Label htmlFor="reg-phone" required>
                  Nomor WhatsApp aktif
                </Label>
                <div className="relative">
                  <Phone className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <Input
                    id="reg-phone"
                    type="tel"
                    inputMode="tel"
                    className="pl-9"
                    value={values.phone}
                    placeholder="0812xxxxxxx"
                    error={err('phone')}
                    autoComplete="tel"
                    onChange={(e) => set('phone', e.target.value)}
                  />
                </div>
                {err('phone') && <p className="mt-1 text-xs text-red-500">{err('phone')}</p>}
              </div>
            </div>

            <div>
              <Label htmlFor="reg-address" required>
                Alamat domisili
              </Label>
              <Textarea
                id="reg-address"
                value={values.address}
                placeholder="Jalan, RT/RW, kelurahan, kecamatan, kota/kabupaten"
                error={err('address')}
                autoComplete="street-address"
                onChange={(e) => set('address', e.target.value)}
              />
              {err('address') && <p className="mt-1 text-xs text-red-500">{err('address')}</p>}
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <Label htmlFor="reg-password" required>
                  Password
                </Label>
                <div className="relative">
                  <Lock className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <Input
                    id="reg-password"
                    type="password"
                    className="pl-9"
                    value={values.password}
                    placeholder={`Minimal ${PASSWORD_MIN} karakter`}
                    error={err('password')}
                    autoComplete="new-password"
                    onChange={(e) => set('password', e.target.value)}
                  />
                </div>
                {err('password') && <p className="mt-1 text-xs text-red-500">{err('password')}</p>}
              </div>
              <div>
                <Label htmlFor="reg-password2" required>
                  Konfirmasi password
                </Label>
                <div className="relative">
                  <Lock className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <Input
                    id="reg-password2"
                    type="password"
                    className="pl-9"
                    value={values.password_confirmation}
                    placeholder="Tulis ulang password"
                    error={err('password_confirmation')}
                    autoComplete="new-password"
                    onChange={(e) => set('password_confirmation', e.target.value)}
                  />
                </div>
                {err('password_confirmation') && (
                  <p className="mt-1 text-xs text-red-500">{err('password_confirmation')}</p>
                )}
              </div>
            </div>

            <div className="flex items-start gap-2 rounded-lg bg-brand-50 px-3 py-2.5 text-xs text-brand-700">
              <Info className="mt-0.5 h-4 w-4 shrink-0" />
              <span>
                Setelah akun jadi, Anda menambahkan anak pada menu <strong>Anak saya</strong>, lalu
                mendaftarkan masing-masing anak pada periode yang dibuka. Menambah anak belum berarti
                terdaftar.
              </span>
            </div>

            <Button type="submit" size="lg" className="w-full" loading={registerMutation.isPending}>
              Buat akun
            </Button>

            <p className="text-center text-sm text-slate-500">
              Sudah punya akun?{' '}
              <Link to={`/ppdb/login?next=${encodeURIComponent(next)}`} className="font-medium text-brand-600 hover:underline">
                Masuk di sini
              </Link>
            </p>
            <p className="text-center text-xs text-slate-400">
              Ingin melihat syarat dan jadwal dulu?{' '}
              <Link to="/ppdb" className="text-brand-600 hover:underline">
                Lihat informasi PPDB
              </Link>
            </p>
          </form>
        </Card>
      )}
    </PublicShell>
  );
}

export default PpdbRegisterPage;
