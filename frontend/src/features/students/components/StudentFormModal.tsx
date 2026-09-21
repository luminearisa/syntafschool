import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Plus, Trash2, Star } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useOptions } from '@/hooks/useOptions';
import { useRegionCascade } from '@/features/students/hooks/useRegionCascade';
import { guardiansApi } from '@/features/students/api/guardiansApi';
import type {
  BloodType,
  Guardian,
  Student,
  StudentGuardianInput,
  StudentPayload,
} from '@/types';

interface Props {
  open: boolean;
  editing: Student | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: StudentPayload) => void;
}

const BLOOD_TYPES: BloodType[] = ['A', 'B', 'AB', 'O'];

const toId = (value: string | number): number | '' => (value === '' ? '' : Number(value));

const emptyGuardian = (isPrimary = false): StudentGuardianInput => ({
  guardian_id: '',
  full_name: null,
  guardian_relation_id: '',
  is_primary: isPrimary,
});

const emptyForm = (): StudentPayload => ({
  school_id: '',
  nis: '',
  nisn: null,
  full_name: '',
  nick_name: null,
  gender_id: '',
  religion_id: '',
  birth_place: null,
  birth_date: null,
  blood_type: null,
  address: null,
  province_id: null,
  city_id: null,
  district_id: null,
  village_id: null,
  postal_code: null,
  phone: null,
  email: null,
  residence_type_id: '',
  transportation_id: '',
  child_order: '',
  sibling_count: '',
  previous_school: null,
  admission_date: null,
  status_id: '',
  is_active: true,
  guardians: [],
});

function Section({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
  return (
    <div className="sm:col-span-2">
      <div className="mb-3 border-b border-slate-100 pb-2">
        <h4 className="text-sm font-semibold text-slate-800">{title}</h4>
        {hint && <p className="text-xs text-slate-500">{hint}</p>}
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </div>
  );
}

export function StudentFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<StudentPayload>(emptyForm());
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: genderOptions } = useOptions('genders');
  const { options: religionOptions } = useOptions('religions');
  const { options: statusOptions } = useOptions('student-statuses');
  const { options: schoolOptions } = useOptions('schools');
  const { options: residenceOptions } = useOptions('residence-types');
  const { options: transportationOptions } = useOptions('transportations');
  const { options: relationOptions } = useOptions('guardian-relations');
  const { options: occupationOptions } = useOptions('occupations');

  const region = useRegionCascade({
    province_id: values.province_id,
    city_id: values.city_id,
    district_id: values.district_id,
  });

  // Daftar wali yang sudah ada, untuk dipilih saat menautkan.
  const guardianQuery = useQuery({
    queryKey: ['guardians', 'options'],
    enabled: open,
    staleTime: 1000 * 60 * 5,
    queryFn: () => guardiansApi.list({ all: true }),
  });

  const guardianOptions = useMemo(
    () =>
      (guardianQuery.data?.data ?? []).map((g: Guardian) => ({
        value: g.id,
        label: g.full_name,
      })),
    [guardianQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (!editing) {
      setValues(emptyForm());
      return;
    }

    setValues({
      school_id: editing.school_id ?? '',
      nis: editing.nis ?? '',
      nisn: editing.nisn ?? null,
      full_name: editing.full_name ?? '',
      nick_name: editing.nick_name ?? null,
      gender_id: editing.gender_id ?? '',
      religion_id: editing.religion_id ?? '',
      birth_place: editing.birth_place ?? null,
      birth_date: editing.birth_date ?? null,
      blood_type: editing.blood_type ?? null,
      address: editing.address ?? null,
      province_id: editing.province_id ?? null,
      city_id: editing.city_id ?? null,
      district_id: editing.district_id ?? null,
      village_id: editing.village_id ?? null,
      postal_code: editing.postal_code ?? null,
      phone: editing.phone ?? null,
      email: editing.email ?? null,
      residence_type_id: editing.residence_type_id ?? '',
      transportation_id: editing.transportation_id ?? '',
      child_order: editing.child_order ?? '',
      sibling_count: editing.sibling_count ?? '',
      previous_school: editing.previous_school ?? null,
      admission_date: editing.admission_date ?? null,
      status_id: editing.status_id ?? '',
      is_active: editing.is_active,
      guardians: (editing.guardians ?? []).map((g) => ({
        guardian_id: g.id,
        full_name: g.full_name,
        guardian_relation_id: g.guardian_relation_id ?? '',
        is_primary: g.is_primary,
        phone: g.phone,
        email: g.email,
        occupation_id: g.occupation_id ?? '',
      })),
    });
  }, [open, editing]);

  const set = <K extends keyof StudentPayload>(name: K, value: StudentPayload[K]) => {
    setValues((prev) => {
      const next = { ...prev, [name]: value };

      // Menurunkan tingkat wilayah otomatis mengosongkan tingkat di bawahnya.
      if (name === 'province_id') {
        next.city_id = null;
        next.district_id = null;
        next.village_id = null;
      }
      if (name === 'city_id') {
        next.district_id = null;
        next.village_id = null;
      }
      if (name === 'district_id') {
        next.village_id = null;
      }

      return next;
    });
    setErrors((prev) => {
      const next = { ...prev };
      delete next[name as string];
      return next;
    });
  };

  const guardians = values.guardians ?? [];

  const setGuardian = (index: number, patch: Partial<StudentGuardianInput>) => {
    setValues((prev) => ({
      ...prev,
      guardians: (prev.guardians ?? []).map((row, i) =>
        i === index ? { ...row, ...patch } : row,
      ),
    }));
  };

  const addGuardian = () => {
    setValues((prev) => {
      const rows = prev.guardians ?? [];
      return {
        ...prev,
        guardians: [...rows, emptyGuardian(rows.length === 0)],
      };
    });
  };

  const removeGuardian = (index: number) => {
    setValues((prev) => {
      const rows = (prev.guardians ?? []).filter((_, i) => i !== index);
      // Pastikan selalu ada tepat satu wali utama.
      if (rows.length > 0 && !rows.some((r) => r.is_primary)) {
        rows[0] = { ...rows[0], is_primary: true };
      }
      return { ...prev, guardians: rows };
    });
  };

  const markPrimary = (index: number) => {
    setValues((prev) => ({
      ...prev,
      guardians: (prev.guardians ?? []).map((row, i) => ({
        ...row,
        is_primary: i === index,
      })),
    }));
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};

    if (!values.nis.trim()) local.nis = 'NIS wajib diisi';
    if (!values.full_name.trim()) local.full_name = 'Nama lengkap wajib diisi';

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({ ...values, guardians });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Data Siswa' : 'Tambah Siswa'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="student-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="student-form" onSubmit={handleSubmit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Section title="Identitas Siswa">
          <div>
            <Label required>NIS</Label>
            <Input
              value={values.nis}
              error={err('nis')}
              placeholder="Nomor Induk Siswa"
              onChange={(e) => set('nis', e.target.value)}
            />
            {err('nis') && <p className="mt-1 text-xs text-red-600">{err('nis')}</p>}
          </div>
          <div>
            <Label>NISN</Label>
            <Input
              value={values.nisn ?? ''}
              error={err('nisn')}
              placeholder="Nomor Induk Siswa Nasional"
              onChange={(e) => set('nisn', e.target.value || null)}
            />
            {err('nisn') && <p className="mt-1 text-xs text-red-600">{err('nisn')}</p>}
          </div>

          <div>
            <Label required>Nama Lengkap</Label>
            <Input
              value={values.full_name}
              error={err('full_name')}
              placeholder="Contoh: Adi Nugroho"
              onChange={(e) => set('full_name', e.target.value)}
            />
            {err('full_name') && <p className="mt-1 text-xs text-red-600">{err('full_name')}</p>}
          </div>
          <div>
            <Label>Nama Panggilan</Label>
            <Input
              value={values.nick_name ?? ''}
              onChange={(e) => set('nick_name', e.target.value || null)}
            />
          </div>

          <div>
            <Label>Jenis Kelamin</Label>
            <SearchSelect
              options={genderOptions}
              value={values.gender_id ?? ''}
              onChange={(v) => set('gender_id', toId(v))}
              placeholder="-- Pilih Jenis Kelamin --"
            />
          </div>
          <div>
            <Label>Agama</Label>
            <SearchSelect
              options={religionOptions}
              value={values.religion_id ?? ''}
              onChange={(v) => set('religion_id', toId(v))}
              placeholder="-- Pilih Agama --"
            />
          </div>

          <div>
            <Label>Tempat Lahir</Label>
            <Input
              value={values.birth_place ?? ''}
              onChange={(e) => set('birth_place', e.target.value || null)}
            />
          </div>
          <div>
            <Label>Tanggal Lahir</Label>
            <Input
              type="date"
              value={values.birth_date ?? ''}
              error={err('birth_date')}
              onChange={(e) => set('birth_date', e.target.value || null)}
            />
            {err('birth_date') && <p className="mt-1 text-xs text-red-600">{err('birth_date')}</p>}
          </div>

          <div>
            <Label>Golongan Darah</Label>
            <Select
              value={values.blood_type ?? ''}
              onChange={(e) => set('blood_type', (e.target.value || null) as BloodType | null)}
            >
              <option value="">-- Tidak diketahui --</option>
              {BLOOD_TYPES.map((b) => (
                <option key={b} value={b}>
                  {b}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Status Siswa</Label>
            <SearchSelect
              options={statusOptions}
              value={values.status_id ?? ''}
              onChange={(v) => set('status_id', toId(v))}
              placeholder="-- Pilih Status --"
            />
          </div>
        </Section>

        <Section
          title="Alamat & Wilayah"
          hint="Wilayah berjenjang: pilih provinsi dulu, lalu kabupaten/kota, kecamatan, dan desa."
        >
          <div className="sm:col-span-2">
            <Label>Alamat</Label>
            <Textarea
              value={values.address ?? ''}
              placeholder="Jalan, nomor rumah, RT/RW"
              onChange={(e) => set('address', e.target.value || null)}
            />
          </div>

          <div>
            <Label>Provinsi</Label>
            <SearchSelect
              options={region.provinceOptions}
              value={values.province_id ?? ''}
              onChange={(v) => set('province_id', v === '' ? null : String(v))}
              placeholder="-- Pilih Provinsi --"
            />
          </div>
          <div>
            <Label>Kabupaten / Kota</Label>
            <SearchSelect
              options={region.cityOptions}
              value={values.city_id ?? ''}
              disabled={!values.province_id}
              onChange={(v) => set('city_id', v === '' ? null : String(v))}
              placeholder={values.province_id ? '-- Pilih Kabupaten/Kota --' : 'Pilih provinsi dulu'}
            />
          </div>
          <div>
            <Label>Kecamatan</Label>
            <SearchSelect
              options={region.districtOptions}
              value={values.district_id ?? ''}
              disabled={!values.city_id}
              onChange={(v) => set('district_id', v === '' ? null : String(v))}
              placeholder={values.city_id ? '-- Pilih Kecamatan --' : 'Pilih kabupaten/kota dulu'}
            />
          </div>
          <div>
            <Label>Desa / Kelurahan</Label>
            <SearchSelect
              options={region.villageOptions}
              value={values.village_id ?? ''}
              disabled={!values.district_id}
              onChange={(v) => set('village_id', v === '' ? null : String(v))}
              placeholder={values.district_id ? '-- Pilih Desa/Kelurahan --' : 'Pilih kecamatan dulu'}
            />
          </div>

          <div>
            <Label>Kode Pos</Label>
            <Input
              value={values.postal_code ?? ''}
              placeholder="Terisi otomatis dari desa"
              onChange={(e) => set('postal_code', e.target.value || null)}
            />
          </div>
          <div>
            <Label>Telepon</Label>
            <Input
              value={values.phone ?? ''}
              onChange={(e) => set('phone', e.target.value || null)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Email</Label>
            <Input
              type="email"
              value={values.email ?? ''}
              error={err('email')}
              onChange={(e) => set('email', e.target.value || null)}
            />
            {err('email') && <p className="mt-1 text-xs text-red-600">{err('email')}</p>}
          </div>
        </Section>

        <Section title="Data Tambahan">
          <div>
            <Label>Tempat Tinggal</Label>
            <SearchSelect
              options={residenceOptions}
              value={values.residence_type_id ?? ''}
              onChange={(v) => set('residence_type_id', toId(v))}
              placeholder="-- Pilih Tempat Tinggal --"
            />
          </div>
          <div>
            <Label>Transportasi</Label>
            <SearchSelect
              options={transportationOptions}
              value={values.transportation_id ?? ''}
              onChange={(v) => set('transportation_id', toId(v))}
              placeholder="-- Pilih Transportasi --"
            />
          </div>
          <div>
            <Label>Anak Ke-</Label>
            <Input
              type="number"
              min={1}
              value={values.child_order === '' ? '' : String(values.child_order ?? '')}
              onChange={(e) => set('child_order', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Jumlah Saudara</Label>
            <Input
              type="number"
              min={0}
              value={values.sibling_count === '' ? '' : String(values.sibling_count ?? '')}
              onChange={(e) => set('sibling_count', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Asal Sekolah</Label>
            <Input
              value={values.previous_school ?? ''}
              placeholder="Contoh: SMP Negeri 1"
              onChange={(e) => set('previous_school', e.target.value || null)}
            />
          </div>
          <div>
            <Label>Tanggal Masuk</Label>
            <Input
              type="date"
              value={values.admission_date ?? ''}
              onChange={(e) => set('admission_date', e.target.value || null)}
            />
          </div>
          <div>
            <Label>Sekolah</Label>
            <SearchSelect
              options={schoolOptions}
              value={values.school_id ?? ''}
              onChange={(v) => set('school_id', toId(v))}
              placeholder="-- Pilih Sekolah --"
            />
          </div>
          <div className="flex items-end">
            <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                checked={values.is_active}
                onChange={(e) => set('is_active', e.target.checked)}
              />
              Siswa aktif
            </label>
          </div>
        </Section>

        <div className="sm:col-span-2">
          <div className="mb-3 flex items-center justify-between border-b border-slate-100 pb-2">
            <div>
              <h4 className="text-sm font-semibold text-slate-800">Wali Murid</h4>
              <p className="text-xs text-slate-500">
                Satu siswa hanya boleh punya satu wali utama.
              </p>
            </div>
            <Button type="button" variant="outline" size="sm" onClick={addGuardian}>
              <Plus className="h-3.5 w-3.5" /> Tambah Wali
            </Button>
          </div>

          {err('guardians') && (
            <p className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
              {err('guardians')}
            </p>
          )}

          {guardians.length === 0 ? (
            <p className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-xs text-slate-400">
              Belum ada wali terhubung.
            </p>
          ) : (
            <div className="space-y-3">
              {guardians.map((row, index) => (
                <div
                  key={index}
                  className="rounded-lg border border-slate-200 bg-slate-50/60 p-3"
                >
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                      <Label>Wali Terdaftar</Label>
                      <SearchSelect
                        options={guardianOptions}
                        value={row.guardian_id ?? ''}
                        onChange={(v) => {
                          const id = toId(v);
                          const picked = guardianQuery.data?.data.find((g) => g.id === id);
                          setGuardian(index, {
                            guardian_id: id,
                            full_name: picked?.full_name ?? row.full_name ?? null,
                          });
                        }}
                        placeholder="-- Pilih wali yang sudah ada --"
                      />
                    </div>
                    <div>
                      <Label>Atau Nama Wali Baru</Label>
                      <Input
                        value={row.full_name ?? ''}
                        disabled={!!row.guardian_id}
                        placeholder="Contoh: Bapak Santoso"
                        onChange={(e) =>
                          setGuardian(index, { full_name: e.target.value || null })
                        }
                      />
                    </div>
                    <div>
                      <Label>Hubungan</Label>
                      <SearchSelect
                        options={relationOptions}
                        value={row.guardian_relation_id ?? ''}
                        onChange={(v) => setGuardian(index, { guardian_relation_id: toId(v) })}
                        placeholder="-- Pilih Hubungan --"
                      />
                    </div>
                    <div>
                      <Label>Pekerjaan</Label>
                      <SearchSelect
                        options={occupationOptions}
                        value={row.occupation_id ?? ''}
                        onChange={(v) => setGuardian(index, { occupation_id: toId(v) })}
                        placeholder="-- Pilih Pekerjaan --"
                      />
                    </div>
                    <div>
                      <Label>Telepon Wali</Label>
                      <Input
                        value={row.phone ?? ''}
                        onChange={(e) => setGuardian(index, { phone: e.target.value || null })}
                      />
                    </div>
                    <div>
                      <Label>Email Wali</Label>
                      <Input
                        type="email"
                        value={row.email ?? ''}
                        onChange={(e) => setGuardian(index, { email: e.target.value || null })}
                      />
                    </div>
                  </div>

                  <div className="mt-3 flex items-center justify-between">
                    <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                      <input
                        type="radio"
                        name="primary-guardian"
                        className="h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500"
                        checked={row.is_primary}
                        onChange={() => markPrimary(index)}
                      />
                      <Star
                        className={`h-3.5 w-3.5 ${
                          row.is_primary ? 'text-amber-500' : 'text-slate-400'
                        }`}
                      />
                      Wali utama
                    </label>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="text-red-600 hover:bg-red-50"
                      onClick={() => removeGuardian(index)}
                    >
                      <Trash2 className="h-3.5 w-3.5" /> Hapus
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </form>
    </Modal>
  );
}
