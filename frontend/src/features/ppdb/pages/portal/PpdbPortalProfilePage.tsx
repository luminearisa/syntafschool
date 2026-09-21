import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, ShieldCheck, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { ppdbPortalApi } from '../../api/ppdbApi';
import { ACCOUNT_STATUS_HINTS, ACCOUNT_STATUS_LABELS, ACCOUNT_STATUS_TONES } from '../../components/portal/labels';
import type { PpdbAccountStatus } from '@/types/ppdb';
import type { PpdbPortalProfile } from '@/types/ppdbPortal';

interface ProfileFormValues {
  name: string;
  email: string;
  phone: string;
  address: string;
}

/**
 * `user` dan `guardian` boleh null pada respons: akun yang belum tertaut
 * pengguna/wali mengembalikan keduanya kosong, jadi isian form mulai dari kosong.
 */
function toForm(profile: PpdbPortalProfile | null | undefined): ProfileFormValues {
  return {
    name: profile?.user?.name ?? '',
    email: profile?.user?.email ?? '',
    phone: profile?.guardian?.phone ?? profile?.user?.phone ?? '',
    address: profile?.guardian?.address ?? '',
  };
}

function validate(values: ProfileFormValues): Partial<Record<keyof ProfileFormValues, string>> {
  const errors: Partial<Record<keyof ProfileFormValues, string>> = {};
  if (!values.name.trim()) errors.name = 'Nama wajib diisi.';
  else if (values.name.trim().length < 3) errors.name = 'Nama minimal 3 karakter.';
  if (!values.email.trim()) errors.email = 'Email wajib diisi.';
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email.trim())) errors.email = 'Format email tidak valid.';
  if (values.phone.trim() && !/^[0-9+\-\s()]{8,16}$/.test(values.phone.trim())) {
    errors.phone = 'Nomor telepon harus 8 sampai 16 karakter angka.';
  }
  if (values.address.trim().length > 500) errors.address = 'Alamat maksimal 500 karakter.';
  return errors;
}

function AccountBadge({ status }: { status: PpdbAccountStatus | undefined }) {
  if (!status) return null;
  return <Badge tone={ACCOUNT_STATUS_TONES[status] ?? 'gray'}>{ACCOUNT_STATUS_LABELS[status] ?? status}</Badge>;
}

/** Baris label/nilai read-only. */
function FieldRow({ label, value }: { label: string; value: string | number | null | undefined }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 pb-2 last:border-0 last:pb-0">
      <span className="text-xs text-slate-500">{label}</span>
      <span className="text-sm text-slate-800">{value === null || value === undefined || value === '' ? '-' : value}</span>
    </div>
  );
}

/** Profil akun orang tua: kontak, status akun, dan anak terdaftar. */
export function PpdbPortalProfilePage() {
  const queryClient = useQueryClient();
  const authUser = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);

  const profileQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'profile'],
    queryFn: () => ppdbPortalApi.profile(),
    staleTime: 30_000,
  });

  const profile = profileQuery.data ?? null;
  const baseline = useMemo(() => toForm(profile), [profile]);
  const [values, setValues] = useState<ProfileFormValues>(baseline);
  const [edited, setEdited] = useState(false);

  useEffect(() => {
    if (!edited) setValues(baseline);
  }, [baseline, edited]);

  const localErrors = validate(values);
  const serverErrors = getValidationErrors(profileQuery.error);

  const updateMutation = useMutation({
    mutationFn: () =>
      ppdbPortalApi.updateProfile({
        name: values.name.trim(),
        email: values.email.trim(),
        phone: values.phone.trim(),
        address: values.address.trim(),
      }),
    onSuccess: (next) => {
      queryClient.setQueryData(['ppdb', 'portal', 'profile'], next);
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'dashboard'] });
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'auth', 'me'] });
      if (authUser && next.user) {
        setUser({ ...authUser, name: next.user.name, email: next.user.email, phone: next.user.phone });
      }
      setEdited(false);
      toast.success('Profil berhasil diperbarui.');
    },
    onError: (error) => {
      toast.error(getErrorMessage(error));
    },
  });

  if (profileQuery.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-sm text-slate-500">
          <Spinner /> Memuat profil...
        </div>
      </Card>
    );
  }

  if (!profile) {
    return (
      <Card>
        <div className="py-12 text-center">
          <p className="text-sm font-medium text-slate-800">Profil gagal dimuat</p>
          <p className="mt-1 text-xs text-slate-500">{getErrorMessage(profileQuery.error)}</p>
          <Button size="sm" variant="outline" className="mt-3" onClick={() => profileQuery.refetch()}>
            Coba lagi
          </Button>
        </div>
      </Card>
    );
  }

  const guardian = profile.guardian;
  const errorFor = (key: keyof ProfileFormValues) => localErrors[key] ?? serverErrors?.[key]?.[0];
  const dirty =
    values.name !== baseline.name ||
    values.email !== baseline.email ||
    values.phone !== baseline.phone ||
    values.address !== baseline.address;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Profil saya</h1>
        <p className="text-sm text-slate-500">Data ini menjadi kanal kontak panitia PPDB selama proses seleksi.</p>
      </div>

      <Card className="p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="min-w-0">
            <p className="truncate font-semibold text-slate-900">
              {profile.user?.name ?? profile.account.name ?? '-'}
            </p>
            <p className="truncate text-xs text-slate-500">
              {profile.user?.email ?? profile.account.email ?? '-'}
            </p>
          </div>
          <AccountBadge status={profile.account.account_status} />
        </div>
        <p className="mt-2 text-xs text-slate-500">{ACCOUNT_STATUS_HINTS[profile.account.account_status]}</p>
        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
          <span className="inline-flex items-center gap-1">
            <Users className="h-3.5 w-3.5" />
            {profile.children_count} anak terdaftar
          </span>
          <Link to="/ppdb/portal/applicants" className="font-medium text-brand-600 hover:underline">
            Kelola data anak
          </Link>
        </div>
      </Card>

      <Card className="p-4">
        <h2 className="mb-3 text-sm font-semibold text-slate-900">Kontak & alamat</h2>
        <form
          className="space-y-4"
          onSubmit={(event) => {
            event.preventDefault();
            if (Object.keys(validate(values)).length > 0) {
              toast.error('Periksa kembali isian yang ditandai merah.');
              return;
            }
            updateMutation.mutate();
          }}
        >
          <div>
            <Label htmlFor="profile-name" required>
              Nama lengkap
            </Label>
            <Input
              id="profile-name"
              value={values.name}
              error={errorFor('name')}
              onChange={(event) => {
                setEdited(true);
                setValues((prev) => ({ ...prev, name: event.target.value }));
              }}
            />
            {errorFor('name') && <p className="mt-1 text-xs text-red-500">{errorFor('name')}</p>}
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="profile-email" required>
                Email
              </Label>
              <Input
                id="profile-email"
                type="email"
                value={values.email}
                error={errorFor('email')}
                onChange={(event) => {
                  setEdited(true);
                  setValues((prev) => ({ ...prev, email: event.target.value }));
                }}
              />
              {errorFor('email') && <p className="mt-1 text-xs text-red-500">{errorFor('email')}</p>}
            </div>
            <div>
              <Label htmlFor="profile-phone">Nomor telepon aktif</Label>
              <Input
                id="profile-phone"
                type="tel"
                inputMode="tel"
                placeholder="08xxxxxxxxxx"
                value={values.phone}
                error={errorFor('phone')}
                onChange={(event) => {
                  setEdited(true);
                  setValues((prev) => ({ ...prev, phone: event.target.value }));
                }}
              />
              {errorFor('phone') && <p className="mt-1 text-xs text-red-500">{errorFor('phone')}</p>}
              <p className="mt-1 text-[11px] text-slate-500">
                Dipakai panitia untuk menghubungi Anda terkait dokumen dan jadwal seleksi.
              </p>
            </div>
          </div>

          <div>
            <Label htmlFor="profile-address">Alamat</Label>
            <Textarea
              id="profile-address"
              rows={3}
              value={values.address}
              error={errorFor('address')}
              onChange={(event) => {
                setEdited(true);
                setValues((prev) => ({ ...prev, address: event.target.value }));
              }}
            />
            {errorFor('address') && <p className="mt-1 text-xs text-red-500">{errorFor('address')}</p>}
          </div>

          <div className="flex items-center gap-2">
            <Button type="submit" loading={updateMutation.isPending} disabled={!dirty}>
              Simpan perubahan
            </Button>
            <Button
              type="button"
              variant="outline"
              disabled={!dirty}
              onClick={() => {
                setValues(baseline);
                setEdited(false);
              }}
            >
              Batalkan
            </Button>
          </div>
        </form>
      </Card>

      {guardian === null && (
        <Card className="border-amber-200 bg-amber-50/60 p-3 text-xs text-amber-900">
          Data wali Anda belum terhubung ke akun ini. Panitia akan menautkannya setelah verifikasi akun; perubahan
          kontak di atas tetap tersimpan pada akun Anda.
        </Card>
      )}

      {guardian && (
        <Card className="p-4">
          <h2 className="mb-2 text-sm font-semibold text-slate-900">Data wali tersimpan</h2>
          <div className="space-y-2">
            <FieldRow label="Nama wali" value={guardian.full_name} />
            <FieldRow label="Pekerjaan" value={guardian.occupation?.name} />
            <FieldRow label="Penghasilan" value={guardian.income_range?.name} />
            <FieldRow label="Pendidikan terakhir" value={guardian.education_background?.name} />
          </div>
          <p className="mt-2 text-[11px] text-slate-500">
            Pekerjaan, penghasilan, pendidikan, jenis kelamin, dan agama diambil dari data master sekolah sehingga
            hanya panitia yang dapat mengubahnya. Sampaikan perubahan melalui kanal kontak periode.
          </p>
        </Card>
      )}

      <Card className="p-4">
        <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-900">
          <ShieldCheck className="h-4 w-4 text-brand-600" />
          Keamanan akun
        </h2>
        <div className="space-y-2">
          <FieldRow label="Email masuk" value={profile.user?.email ?? profile.account.email} />
          <FieldRow label="Tautan foto profil" value={profile.user?.avatar_path ? 'tersedia' : null} />
        </div>
        <p className="mt-2 flex items-start gap-1.5 text-[11px] text-slate-500">
          <KeyRound className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          Perubahan kata sandi dilakukan melalui tautan resmi dari panitia. Jika Anda belum menerimanya, hubungi
          panitia PPDB dengan menyebutkan email akun ini.
        </p>
      </Card>
    </div>
  );
}

export default PpdbPortalProfilePage;
