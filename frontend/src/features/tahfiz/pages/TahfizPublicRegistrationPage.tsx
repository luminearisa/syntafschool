import { useState, type FormEvent } from 'react';
import { CheckCircle2 } from 'lucide-react';
import { registrationsApi } from '../api/tahfizApi';
import { useProgramOptions } from '../hooks/useTahfizOptions';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import {
  EXPERIENCE_LEVEL_LABELS,
  GENDER_LABELS,
  READING_ABILITY_LABELS,
  toOptions,
} from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { TahfizRegistrationPayload } from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

interface FormState {
  name: string;
  gender: string;
  birth_date: string;
  phone: string;
  email: string;
  address: string;
  program_id: number | '';
  experience_level: string;
  last_memorized: string;
  reading_ability: string;
  memorization_target: string;
  schedule_preference: string;
}

const emptyForm: FormState = {
  name: '',
  gender: '',
  birth_date: '',
  phone: '',
  email: '',
  address: '',
  program_id: '',
  experience_level: '',
  last_memorized: '',
  reading_ability: '',
  memorization_target: '',
  schedule_preference: '',
};

/**
 * Formulir pendaftaran mandiri Tahfiz (PUBLIC).
 *
 * Dipanggil tanpa autentikasi dan dirender di luar shell aplikasi, sehingga
 * dibungkus sebagai kartu terpusat. Tidak ada permission gate.
 */
export function TahfizPublicRegistrationPage() {
  const [values, setValues] = useState<FormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [globalError, setGlobalError] = useState('');

  const { options: programOptions } = useProgramOptions();

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const copy = { ...prev };
      delete copy[key];
      return copy;
    });
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.name.trim()) local.name = 'Nama wajib diisi';
    if (!values.program_id) local.program_id = 'Program wajib dipilih';

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    const payload: TahfizRegistrationPayload = {
      name: values.name,
      gender: (values.gender || '') as TahfizRegistrationPayload['gender'],
      birth_date: values.birth_date,
      phone: values.phone,
      email: values.email,
      address: values.address,
      program_id: values.program_id,
      experience_level: (values.experience_level || '') as TahfizRegistrationPayload['experience_level'],
      last_memorized: values.last_memorized,
      reading_ability: (values.reading_ability || '') as TahfizRegistrationPayload['reading_ability'],
      memorization_target: values.memorization_target,
      schedule_preference: values.schedule_preference,
    };

    setSubmitting(true);
    try {
      await registrationsApi.submit(payload);
      setSubmitted(true);
    } catch (err) {
      setServerErrors(getValidationErrors(err) ?? {});
      setErrors({});
      // Tampilkan pesan error global lewat alert teks.
      setGlobalError(getErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <Card className="w-full max-w-xl p-6">
        <div className="mb-4 text-center">
          <h1 className="text-lg font-semibold text-slate-900">Pendaftaran Tahfiz / Al-Qur'an</h1>
          <p className="mt-1 text-sm text-slate-500">
            Isi formulir berikut untuk mengajukan pendaftaran program tahfiz.
          </p>
        </div>

        {submitted ? (
          <div className="flex flex-col items-center gap-3 py-8 text-center">
            <div className="rounded-full bg-green-100 p-3 text-green-600">
              <CheckCircle2 className="h-8 w-8" />
            </div>
            <p className="text-base font-medium text-slate-800">
              Pendaftaran terkirim, menunggu verifikasi admin
            </p>
            <p className="text-sm text-slate-500">
              Pengajuan Anda akan ditinjau oleh admin. Silakan hubungi admin untuk informasi lebih
              lanjut.
            </p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            {globalError && (
              <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{globalError}</div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Label required>Nama Lengkap</Label>
                <Input
                  value={values.name}
                  error={err('name')}
                  placeholder="Nama lengkap"
                  onChange={(e) => set('name', e.target.value)}
                />
              </div>
              <div>
                <Label>Jenis Kelamin</Label>
                <Select
                  value={values.gender}
                  error={err('gender')}
                  onChange={(e) => set('gender', e.target.value)}
                >
                  <option value="">-- Pilih --</option>
                  {toOptions(GENDER_LABELS).map((o) => (
                    <option key={String(o.value)} value={String(o.value)}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>Tanggal Lahir</Label>
                <Input
                  type="date"
                  value={values.birth_date}
                  error={err('birth_date')}
                  onChange={(e) => set('birth_date', e.target.value)}
                />
              </div>
              <div>
                <Label>Telepon</Label>
                <Input
                  value={values.phone}
                  error={err('phone')}
                  placeholder="mis. 0812xxxxxxx"
                  onChange={(e) => set('phone', e.target.value)}
                />
              </div>
              <div>
                <Label>Email</Label>
                <Input
                  type="email"
                  value={values.email}
                  error={err('email')}
                  placeholder="nama@email.com"
                  onChange={(e) => set('email', e.target.value)}
                />
              </div>
              <div className="sm:col-span-2">
                <Label>Alamat</Label>
                <Textarea
                  value={values.address}
                  error={err('address')}
                  placeholder="Alamat lengkap"
                  onChange={(e) => set('address', e.target.value)}
                />
              </div>

              <div className="sm:col-span-2">
                <Label required>Program</Label>
                <SearchSelect
                  options={programOptions}
                  value={values.program_id}
                  error={err('program_id')}
                  onChange={(v) => set('program_id', toId(v))}
                  placeholder="-- Pilih Program --"
                />
              </div>
              <div>
                <Label>Tingkat Pengalaman</Label>
                <Select
                  value={values.experience_level}
                  error={err('experience_level')}
                  onChange={(e) => set('experience_level', e.target.value)}
                >
                  <option value="">-- Pilih --</option>
                  {toOptions(EXPERIENCE_LEVEL_LABELS as Record<string, string>).map((o) => (
                    <option key={String(o.value)} value={String(o.value)}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>Kemampuan Membaca</Label>
                <Select
                  value={values.reading_ability}
                  error={err('reading_ability')}
                  onChange={(e) => set('reading_ability', e.target.value)}
                >
                  <option value="">-- Pilih --</option>
                  {toOptions(READING_ABILITY_LABELS as Record<string, string>).map((o) => (
                    <option key={String(o.value)} value={String(o.value)}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <Label>Hafalan Terakhir</Label>
                <Input
                  value={values.last_memorized}
                  error={err('last_memorized')}
                  placeholder="mis. Juz 5"
                  onChange={(e) => set('last_memorized', e.target.value)}
                />
              </div>
              <div>
                <Label>Target Hafalan</Label>
                <Input
                  value={values.memorization_target}
                  error={err('memorization_target')}
                  placeholder="mis. 30 Juz"
                  onChange={(e) => set('memorization_target', e.target.value)}
                />
              </div>
              <div className="sm:col-span-2">
                <Label>Preferensi Jadwal</Label>
                <Input
                  value={values.schedule_preference}
                  error={err('schedule_preference')}
                  placeholder="mis. Pagi hari / Akhir pekan"
                  onChange={(e) => set('schedule_preference', e.target.value)}
                />
              </div>
            </div>

            <div className="flex justify-end">
              <Button type="submit" loading={submitting}>
                Kirim Pendaftaran
              </Button>
            </div>
          </form>
        )}
      </Card>
    </div>
  );
}