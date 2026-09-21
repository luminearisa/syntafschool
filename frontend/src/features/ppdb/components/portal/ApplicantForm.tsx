import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { User } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatDate, toInputDate } from './format';
import type { PpdbApplicant } from '@/types/ppdb';

/** Kolom calon siswa yang dapat diisi orang tua tanpa referensi master data. */
export interface ApplicantFormValues {
  full_name: string;
  nickname: string;
  birth_place: string;
  birth_date: string;
  nik: string;
  nisn: string;
  address: string;
  postal_code: string;
  previous_school: string;
  previous_school_npsn: string;
}

export const emptyApplicantForm: ApplicantFormValues = {
  full_name: '',
  nickname: '',
  birth_place: '',
  birth_date: '',
  nik: '',
  nisn: '',
  address: '',
  postal_code: '',
  previous_school: '',
  previous_school_npsn: '',
};

export function applicantToForm(applicant: Partial<PpdbApplicant> | null | undefined): ApplicantFormValues {
  if (!applicant) return { ...emptyApplicantForm };
  return {
    full_name: applicant.full_name ?? '',
    nickname: applicant.nickname ?? '',
    birth_place: applicant.birth_place ?? '',
    birth_date: toInputDate(applicant.birth_date),
    nik: applicant.nik ?? '',
    nisn: applicant.nisn ?? '',
    address: applicant.address ?? '',
    postal_code: applicant.postal_code ?? '',
    previous_school: applicant.previous_school ?? '',
    previous_school_npsn: applicant.previous_school_npsn ?? '',
  };
}

/** Nilai kosong dikirim sebagai `null` supaya kolom lama benar-benar dibersihkan. */
export function formToApplicant(values: ApplicantFormValues): Partial<PpdbApplicant> {
  const text = (value: string) => (value.trim() === '' ? null : value.trim());
  return {
    full_name: values.full_name.trim(),
    nickname: text(values.nickname),
    birth_place: text(values.birth_place),
    birth_date: text(values.birth_date),
    nik: text(values.nik),
    nisn: text(values.nisn),
    address: text(values.address),
    postal_code: text(values.postal_code),
    previous_school: text(values.previous_school),
    previous_school_npsn: text(values.previous_school_npsn),
  };
}

export function validateApplicantForm(values: ApplicantFormValues): Record<string, string> {
  const errors: Record<string, string> = {};
  if (!values.full_name.trim()) errors.full_name = 'Nama lengkap wajib diisi.';
  else if (values.full_name.trim().length < 3) errors.full_name = 'Nama lengkap minimal 3 karakter.';
  if (values.birth_date && Number.isNaN(new Date(values.birth_date).getTime())) {
    errors.birth_date = 'Tanggal lahir tidak valid.';
  }
  if (values.nik && !/^\d{16}$/.test(values.nik)) errors.nik = 'NIK harus 16 digit angka.';
  if (values.nisn && !/^\d{8,10}$/.test(values.nisn)) errors.nisn = 'NISN harus 8 sampai 10 digit angka.';
  if (values.postal_code && !/^\d{5}$/.test(values.postal_code)) {
    errors.postal_code = 'Kode pos harus 5 digit angka.';
  }
  if (values.previous_school_npsn && !/^\d{8}$/.test(values.previous_school_npsn)) {
    errors.previous_school_npsn = 'NPSN harus 8 digit angka.';
  }
  return errors;
}

interface FormProps {
  values: ApplicantFormValues;
  errors?: Record<string, string | undefined>;
  onChange: (key: keyof ApplicantFormValues, value: string) => void;
  disabled?: boolean;
  withSchool?: boolean;
  className?: string;
}

const FIELDS: {
  key: keyof ApplicantFormValues;
  label: string;
  type?: string;
  placeholder?: string;
  required?: boolean;
  full?: boolean;
  inputMode?: 'numeric' | 'tel';
  maxLength?: number;
  group: 'identity' | 'birth' | 'contact' | 'school';
}[] = [
  { key: 'full_name', label: 'Nama Lengkap', required: true, full: true, group: 'identity' },
  { key: 'nickname', label: 'Nama Panggilan', placeholder: 'mis. Nadia', group: 'identity' },
  { key: 'nik', label: 'NIK', placeholder: '16 digit sesuai KTP/KK', inputMode: 'numeric', maxLength: 16, group: 'identity' },
  { key: 'birth_place', label: 'Tempat Lahir', placeholder: 'mis. Bandung', group: 'birth' },
  { key: 'birth_date', label: 'Tanggal Lahir', type: 'date', group: 'birth' },
  { key: 'nisn', label: 'NISN', placeholder: 'Nomor Induk Siswa Nasional', inputMode: 'numeric', maxLength: 10, group: 'birth' },
  { key: 'address', label: 'Alamat Lengkap', full: true, placeholder: 'Jalan, RT/RW, kelurahan, kecamatan, kota', group: 'contact' },
  { key: 'postal_code', label: 'Kode Pos', inputMode: 'numeric', maxLength: 5, group: 'contact' },
  { key: 'previous_school', label: 'Sekolah Asal', placeholder: 'mis. SD Negeri 1 Cibadak', full: true, group: 'school' },
  { key: 'previous_school_npsn', label: 'NPSN Sekolah Asal', inputMode: 'numeric', maxLength: 8, group: 'school' },
];

/** Formulir identitas calon siswa — dipakai wizard & halaman anak. */
export function ApplicantForm({ values, errors = {}, onChange, disabled, withSchool = true, className }: FormProps) {
  const visible = FIELDS.filter((field) => withSchool || field.group !== 'school');

  return (
    <div className={cn('grid grid-cols-1 gap-4 sm:grid-cols-2', className)}>
      {visible.map((field) => {
        const error = errors[field.key];
        const id = `applicant-${field.key}`;
        return (
          <div key={field.key} className={cn(field.full && 'sm:col-span-2')}>
            <Label htmlFor={id} required={field.required}>
              {field.label}
            </Label>
            {field.key === 'address' ? (
              <Textarea
                id={id}
                value={values[field.key]}
                placeholder={field.placeholder}
                disabled={disabled}
                error={error}
                onChange={(e) => onChange(field.key, e.target.value)}
              />
            ) : (
              <Input
                id={id}
                type={field.type ?? 'text'}
                inputMode={field.inputMode}
                maxLength={field.maxLength}
                value={values[field.key]}
                placeholder={field.placeholder}
                disabled={disabled}
                error={error}
                onChange={(e) => onChange(field.key, e.target.value)}
              />
            )}
            {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
          </div>
        );
      })}
    </div>
  );
}

/** Foto + identitas singkat calon siswa. */
export function ApplicantIdentity({
  applicant,
  subtitle,
}: {
  applicant: Partial<PpdbApplicant> | null | undefined;
  subtitle?: string | null;
}) {
  const photo = applicant?.photo_path ?? null;
  const birth = [applicant?.birth_place, applicant?.birth_date ? formatDate(applicant.birth_date) : null]
    .filter(Boolean)
    .join(', ');

  return (
    <div className="flex items-start gap-3">
      {photo ? (
        <img
          src={photo}
          alt={applicant?.full_name ?? 'Foto calon siswa'}
          className="h-16 w-14 shrink-0 rounded-lg border border-slate-200 object-cover"
        />
      ) : (
        <div className="flex h-16 w-14 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400">
          <User className="h-6 w-6" />
        </div>
      )}
      <div className="min-w-0">
        <p className="truncate font-semibold text-slate-900">{applicant?.full_name ?? 'Calon siswa'}</p>
        {applicant?.nickname && <p className="truncate text-xs text-slate-500">Panggilan: {applicant.nickname}</p>}
        {birth && <p className="mt-0.5 text-xs text-slate-500">{birth}</p>}
        {(subtitle ?? applicant?.previous_school) && (
          <p className="mt-0.5 text-xs text-slate-500">{subtitle ?? applicant?.previous_school}</p>
        )}
        {typeof applicant?.nisn === 'string' && applicant.nisn && (
          <p className="mt-0.5 text-xs text-slate-500">NISN: {applicant.nisn}</p>
        )}
      </div>
    </div>
  );
}
