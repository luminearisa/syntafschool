import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { useStudentOptions, useEmployeeOptions } from '@/hooks/useLookupOptions';
import { toOptions, PARTICIPANT_TYPE_LABELS, PARTICIPANT_STATUS_LABELS, GENDER_LABELS, EXPERIENCE_LEVEL_LABELS, READING_ABILITY_LABELS } from '../lib/labels';
import type { SelectOption } from '@/constants/masterData';
import type {
  TahfizParticipant,
  TahfizParticipantPayload,
  TahfizParticipantType,
  TahfizParticipantStatus,
  TahfizGender,
} from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizParticipant | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  guardianOptions?: SelectOption[];
  onClose: () => void;
  onSubmit: (payload: TahfizParticipantPayload) => void;
}

interface FormState {
  participant_type: TahfizParticipantType | '';
  student_id: number | '';
  employee_id: number | '';
  guardian_id: number | '';
  name: string;
  gender: TahfizGender | '';
  birth_date: string;
  phone: string;
  email: string;
  address: string;
  notes: string;
  status: TahfizParticipantStatus;
  experience_level: string;
  last_memorized: string;
  reading_ability: string;
  memorization_target: string;
  schedule_preference: string;
  education_background: string;
  occupation: string;
  emergency_contact: string;
}

const SELF_MANAGED: TahfizParticipantType[] = ['public', 'alumni', 'other'];

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const emptyForm: FormState = {
  participant_type: '',
  student_id: '',
  employee_id: '',
  guardian_id: '',
  name: '',
  gender: '',
  birth_date: '',
  phone: '',
  email: '',
  address: '',
  notes: '',
  status: 'active',
  experience_level: '',
  last_memorized: '',
  reading_ability: '',
  memorization_target: '',
  schedule_preference: '',
  education_background: '',
  occupation: '',
  emergency_contact: '',
};

/**
 * Form peserta Tahfiz.
 *
 * Tipe peserta menentukan field yang wajib: tipe terhubung (siswa/pegawai/wali)
 * butuh relasi, sedangkan tipe mandiri (umum/alumni/lainnya) butuh nama.
 */
export function ParticipantFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  guardianOptions,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<FormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: studentOptions } = useStudentOptions();
  const { options: employeeOptions } = useEmployeeOptions(false);

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (editing) {
      const p = editing.profile;
      setValues({
        participant_type: editing.participant_type,
        student_id: editing.student_id ?? '',
        employee_id: editing.employee_id ?? '',
        guardian_id: editing.guardian_id ?? '',
        name: editing.name ?? '',
        gender: editing.gender ?? '',
        birth_date: editing.birth_date ?? '',
        phone: editing.phone ?? '',
        email: editing.email ?? '',
        address: editing.address ?? '',
        notes: editing.notes ?? '',
        status: editing.status,
        experience_level: p?.experience_level ?? '',
        last_memorized: p?.last_memorized ?? '',
        reading_ability: p?.reading_ability ?? '',
        memorization_target: p?.memorization_target ?? '',
        schedule_preference: p?.schedule_preference ?? '',
        education_background: p?.education_background ?? '',
        occupation: p?.occupation ?? '',
        emergency_contact: p?.emergency_contact ?? '',
      });
    } else {
      setValues({ ...emptyForm });
    }
  }, [open, editing]);

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    const type = values.participant_type;

    if (!type) local.participant_type = 'Tipe peserta wajib dipilih';
    if (type === 'student' && !values.student_id) local.student_id = 'Siswa wajib dipilih';
    if (type === 'employee' && !values.employee_id) local.employee_id = 'Pegawai wajib dipilih';
    if (type === 'guardian' && !values.guardian_id) local.guardian_id = 'Wali murid wajib dipilih';
    if (type && SELF_MANAGED.includes(type) && !values.name.trim()) {
      local.name = 'Nama wajib diisi';
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    const payload: TahfizParticipantPayload = {
      participant_type: type as TahfizParticipantType,
      student_id: values.student_id,
      employee_id: values.employee_id,
      guardian_id: values.guardian_id,
      name: values.name,
      gender: values.gender,
      birth_date: values.birth_date,
      phone: values.phone,
      email: values.email,
      address: values.address,
      notes: values.notes,
      status: values.status,
      profile: {
        experience_level: values.experience_level,
        last_memorized: values.last_memorized,
        reading_ability: values.reading_ability,
        memorization_target: values.memorization_target,
        schedule_preference: values.schedule_preference,
        education_background: values.education_background,
        occupation: values.occupation,
        emergency_contact: values.emergency_contact,
      },
    };

    onSubmit(payload);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];
  const isSelfManaged = values.participant_type !== '' && SELF_MANAGED.includes(values.participant_type);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Peserta Tahfiz' : 'Tambah Peserta Tahfiz'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="participant-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="participant-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Tipe Peserta</Label>
            <Select
              value={values.participant_type}
              error={err('participant_type')}
              onChange={(e) => set('participant_type', e.target.value as TahfizParticipantType | '')}
            >
              <option value="">-- Pilih Tipe --</option>
              {(Object.keys(PARTICIPANT_TYPE_LABELS) as TahfizParticipantType[]).map((key) => (
                <option key={key} value={key}>
                  {PARTICIPANT_TYPE_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={values.status}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as TahfizParticipantStatus)}
            >
              {(Object.keys(PARTICIPANT_STATUS_LABELS) as TahfizParticipantStatus[]).map((key) => (
                <option key={key} value={key}>
                  {PARTICIPANT_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>

          {values.participant_type === 'student' && (
            <div className="sm:col-span-2">
              <Label required>Siswa</Label>
              <SearchSelect
                options={studentOptions}
                value={values.student_id}
                error={err('student_id')}
                onChange={(v) => set('student_id', toId(v))}
                placeholder="-- Pilih Siswa --"
              />
            </div>
          )}

          {values.participant_type === 'employee' && (
            <div className="sm:col-span-2">
              <Label required>Pegawai</Label>
              <SearchSelect
                options={employeeOptions}
                value={values.employee_id}
                error={err('employee_id')}
                onChange={(v) => set('employee_id', toId(v))}
                placeholder="-- Pilih Pegawai --"
              />
            </div>
          )}

          {values.participant_type === 'guardian' && (
            <div className="sm:col-span-2">
              <Label required>Wali Murid</Label>
              <SearchSelect
                options={guardianOptions ?? []}
                value={values.guardian_id}
                error={err('guardian_id')}
                onChange={(v) => set('guardian_id', toId(v))}
                placeholder="-- Pilih Wali Murid --"
              />
            </div>
          )}

          {isSelfManaged && (
            <>
              <div className="sm:col-span-2">
                <Label required>Nama</Label>
                <Input
                  value={values.name}
                  error={err('name')}
                  placeholder="Nama lengkap peserta"
                  onChange={(e) => set('name', e.target.value)}
                />
              </div>
              <div>
                <Label>Jenis Kelamin</Label>
                <Select
                  value={values.gender}
                  error={err('gender')}
                  onChange={(e) => set('gender', e.target.value as TahfizGender | '')}
                >
                  <option value="">-- Pilih --</option>
                  {(Object.keys(GENDER_LABELS) as ('male' | 'female')[]).map((key) => (
                    <option key={key} value={key}>
                      {GENDER_LABELS[key]}
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
            </>
          )}
        </div>

        <div>
          <p className="mb-2 text-sm font-semibold text-slate-800">Profil Hafalan</p>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label>Tingkat Pengalaman</Label>
              <Select
                value={values.experience_level}
                error={err('profile.experience_level')}
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
                error={err('profile.reading_ability')}
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
                error={err('profile.last_memorized')}
                placeholder="mis. Juz 5"
                onChange={(e) => set('last_memorized', e.target.value)}
              />
            </div>
            <div>
              <Label>Target Hafalan</Label>
              <Input
                value={values.memorization_target}
                error={err('profile.memorization_target')}
                placeholder="mis. 30 Juz"
                onChange={(e) => set('memorization_target', e.target.value)}
              />
            </div>
            <div>
              <Label>Preferensi Jadwal</Label>
              <Input
                value={values.schedule_preference}
                error={err('profile.schedule_preference')}
                placeholder="mis. Pagi hari"
                onChange={(e) => set('schedule_preference', e.target.value)}
              />
            </div>
            <div>
              <Label>Latar Belakang Pendidikan</Label>
              <Input
                value={values.education_background}
                error={err('profile.education_background')}
                placeholder="mis. SMP"
                onChange={(e) => set('education_background', e.target.value)}
              />
            </div>
            <div>
              <Label>Pekerjaan</Label>
              <Input
                value={values.occupation}
                error={err('profile.occupation')}
                placeholder="mis. Karyawan"
                onChange={(e) => set('occupation', e.target.value)}
              />
            </div>
            <div>
              <Label>Kontak Darurat</Label>
              <Input
                value={values.emergency_contact}
                error={err('profile.emergency_contact')}
                placeholder="Nama & nomor telepon"
                onChange={(e) => set('emergency_contact', e.target.value)}
              />
            </div>
          </div>
        </div>

        <div>
          <Label>Catatan</Label>
          <Textarea
            value={values.notes}
            error={err('notes')}
            placeholder="Catatan tambahan (opsional)"
            onChange={(e) => set('notes', e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}