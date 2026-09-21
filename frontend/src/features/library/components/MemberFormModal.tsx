import { useEffect, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Info } from 'lucide-react';
import { libraryMastersApi } from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { LINKED_TYPE_LABELS, MEMBER_STATUS_LABELS } from '../lib/catalogLabels';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { useEmployeeOptions, useStudentOptions } from '@/hooks/useLookupOptions';
import { useOptions } from '@/hooks/useOptions';
import type {
  LibraryMember,
  LibraryMemberPayload,
  MemberLinkedType,
  MemberStatus,
} from '@/types/libraryCatalog';

/**
 * Tipe tautan yang dapat dipilih di formulir.
 *
 * `guardian` sengaja belum tersedia — belum ada sumber lookup wali murid.
 */
type LinkType = 'student' | 'employee' | 'public';

/** Tipe tautan yang tidak dapat diubah dari formulir ini (hanya bisa dilihat). */
function isLockedLinkType(type: MemberLinkedType | undefined): boolean {
  return type === 'guardian' || type === 'user';
}

const LINK_TYPE_OPTIONS: { value: LinkType; label: string }[] = [
  { value: 'student', label: LINKED_TYPE_LABELS.student },
  { value: 'employee', label: LINKED_TYPE_LABELS.employee },
  { value: 'public', label: LINKED_TYPE_LABELS.public },
];

interface FormState {
  library_id: number | '';
  membership_type_id: number | '';
  student_id: number | '';
  employee_id: number | '';
  name: string;
  gender_id: number | '';
  birth_date: string;
  phone: string;
  email: string;
  address: string;
  identity_number: string;
  photo_path: string;
  registered_at: string;
  valid_from: string;
  valid_until: string;
  status: MemberStatus;
  notes: string;
}

const emptyForm: FormState = {
  library_id: '',
  membership_type_id: '',
  student_id: '',
  employee_id: '',
  name: '',
  gender_id: '',
  birth_date: '',
  phone: '',
  email: '',
  address: '',
  identity_number: '',
  photo_path: '',
  registered_at: '',
  valid_from: '',
  valid_until: '',
  status: 'pending',
  notes: '',
};

function initialLinkType(member: LibraryMember | null): LinkType {
  switch (member?.linked_type) {
    case 'student':
      return 'student';
    case 'employee':
      return 'employee';
    default:
      return 'public';
  }
}

function toForm(member: LibraryMember | null): FormState {
  if (!member) return { ...emptyForm };
  return {
    library_id: member.library_id ?? '',
    membership_type_id: member.membership_type_id ?? '',
    student_id: member.student?.id ?? '',
    employee_id: member.employee?.id ?? '',
    name: member.name ?? '',
    gender_id: member.gender_id ?? '',
    birth_date: member.birth_date ?? '',
    phone: member.phone ?? '',
    email: member.email ?? '',
    address: member.address ?? '',
    identity_number: member.identity_number ?? '',
    photo_path: member.photo_path ?? '',
    registered_at: member.registered_at ?? '',
    valid_from: member.valid_from ?? '',
    valid_until: member.valid_until ?? '',
    status: member.status,
    notes: member.notes ?? '',
  };
}

interface Props {
  open: boolean;
  editing: LibraryMember | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: LibraryMemberPayload) => void;
}

/**
 * Form data anggota perpustakaan.
 *
 * Identitas anggota **ditarik otomatis** dari entitas tertaut (siswa/pegawai),
 * sehingga biodata tidak pernah diinput ulang. Anggota "Umum" tidak punya
 * entitas tertaut, jadi identitasnya diisi langsung di sini.
 */
export function MemberFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<FormState>(emptyForm);
  const [linkType, setLinkType] = useState<LinkType>('public');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: libraryOptions } = useLibraryOptions();
  const { options: genderOptions } = useOptions('genders');
  const { options: studentOptions } = useStudentOptions();
  const { options: employeeOptions } = useEmployeeOptions(false);

  const membershipTypeQuery = useQuery({
    queryKey: ['library', 'masters', 'membership-types', 'options'],
    enabled: open,
    staleTime: 1000 * 60 * 10,
    queryFn: () => libraryMastersApi.list('membership-types', { all: true }),
  });

  const membershipTypeOptions = (membershipTypeQuery.data?.data ?? []).map((row) => ({
    value: row.id,
    label: row.code ? `${row.code} — ${row.name}` : row.name,
  }));

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(toForm(editing));
    setLinkType(initialLinkType(editing));
  }, [open, editing]);

  const lockedType = isLockedLinkType(editing?.linked_type);

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const changeLinkType = (next: LinkType) => {
    // Ganti tipe tautan wajib mengosongkan id tautan lain agar tidak
    // mengirim tautan ganda ke backend.
    setValues((prev) => ({ ...prev, student_id: '', employee_id: '' }));
    setLinkType(next);
    setErrors({});
  };

  const buildPayload = (): LibraryMemberPayload => {
    const payload: LibraryMemberPayload = {
      library_id: values.library_id,
      membership_type_id: values.membership_type_id,
      registered_at: values.registered_at,
      valid_from: values.valid_from,
      valid_until: values.valid_until,
      status: values.status,
      notes: values.notes,
    };

    // Tautan wali/pengguna tidak dapat diubah di sini — jangan kirim id tautan
    // apa pun supaya tautan yang ada tidak tertimpa.
    if (lockedType) return payload;

    if (linkType === 'student') {
      payload.student_id = values.student_id;
    } else if (linkType === 'employee') {
      payload.employee_id = values.employee_id;
    } else {
      payload.name = values.name;
      payload.gender_id = values.gender_id;
      payload.birth_date = values.birth_date;
      payload.phone = values.phone;
      payload.email = values.email;
      payload.address = values.address;
      payload.identity_number = values.identity_number;
      payload.photo_path = values.photo_path;
    }

    return payload;
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!editing && values.library_id === '') local.library_id = 'Perpustakaan wajib dipilih';
    if (!lockedType) {
      if (linkType === 'student' && values.student_id === '') {
        local.student_id = 'Siswa wajib dipilih';
      }
      if (linkType === 'employee' && values.employee_id === '') {
        local.employee_id = 'Pegawai wajib dipilih';
      }
      if (linkType === 'public' && !values.name.trim()) {
        local.name = 'Nama wajib diisi untuk anggota umum';
      }
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit(buildPayload());
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Anggota' : 'Tambah Anggota'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-member-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="library-member-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required={!editing}>Perpustakaan</Label>
            <SearchSelect
              options={libraryOptions}
              value={values.library_id}
              error={err('library_id')}
              placeholder="-- Pilih perpustakaan --"
              onChange={(v) => set('library_id', v === '' ? '' : Number(v))}
            />
          </div>
          <div>
            <Label>Jenis Keanggotaan</Label>
            <SearchSelect
              options={membershipTypeOptions}
              value={values.membership_type_id}
              error={err('membership_type_id')}
              placeholder="-- Opsional --"
              onChange={(v) => set('membership_type_id', v === '' ? '' : Number(v))}
            />
          </div>
        </div>

        <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
          <div className="flex items-start gap-2 pb-3">
            <Info className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
            <p className="text-xs text-slate-500">
              Identitas ditarik otomatis dari entitas tertaut; biodata tidak diinput ulang.
            </p>
          </div>

          {lockedType ? (
            <div>
              <Label>Tipe Tautan</Label>
              <div className="flex h-9 items-center rounded-lg border border-slate-200 bg-slate-100 px-3 text-sm text-slate-600">
                {editing ? LINKED_TYPE_LABELS[editing.linked_type] : '—'}
              </div>
              <p className="mt-1 text-xs text-slate-400">
                Tipe tautan wali/pengguna belum dapat diubah dari formulir ini. Tautan yang ada akan
                dipertahankan.
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              <div>
                <Label required>Tipe Tautan</Label>
                <Select
                  value={linkType}
                  onChange={(e) => changeLinkType(e.target.value as LinkType)}
                >
                  {LINK_TYPE_OPTIONS.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </Select>
                <p className="mt-1 text-xs text-slate-400">
                  Tautan wali murid belum tersedia.
                </p>
              </div>

              {linkType === 'student' && (
                <div>
                  <Label required>Siswa</Label>
                  <SearchSelect
                    options={studentOptions}
                    value={values.student_id}
                    error={err('student_id')}
                    placeholder="-- Pilih siswa --"
                    onChange={(v) => set('student_id', v === '' ? '' : Number(v))}
                  />
                </div>
              )}

              {linkType === 'employee' && (
                <div>
                  <Label required>Pegawai</Label>
                  <SearchSelect
                    options={employeeOptions}
                    value={values.employee_id}
                    error={err('employee_id')}
                    placeholder="-- Pilih pegawai --"
                    onChange={(v) => set('employee_id', v === '' ? '' : Number(v))}
                  />
                </div>
              )}

              {linkType === 'public' && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="sm:col-span-2">
                    <Label required>Nama</Label>
                    <Input
                      value={values.name}
                      error={err('name')}
                      placeholder="Nama lengkap anggota umum"
                      onChange={(e) => set('name', e.target.value)}
                    />
                  </div>

                  {genderOptions.length > 0 && (
                    <div>
                      <Label>Jenis Kelamin</Label>
                      <SearchSelect
                        options={genderOptions}
                        value={values.gender_id}
                        error={err('gender_id')}
                        placeholder="-- Opsional --"
                        onChange={(v) => set('gender_id', v === '' ? '' : Number(v))}
                      />
                    </div>
                  )}

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
                      placeholder="Opsional"
                      onChange={(e) => set('phone', e.target.value)}
                    />
                  </div>
                  <div>
                    <Label>Email</Label>
                    <Input
                      type="email"
                      value={values.email}
                      error={err('email')}
                      placeholder="Opsional"
                      onChange={(e) => set('email', e.target.value)}
                    />
                  </div>
                  <div>
                    <Label>Nomor Identitas</Label>
                    <Input
                      value={values.identity_number}
                      error={err('identity_number')}
                      placeholder="KTP / identitas lain (opsional)"
                      onChange={(e) => set('identity_number', e.target.value)}
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <Label>Foto (path)</Label>
                    <Input
                      value={values.photo_path}
                      error={err('photo_path')}
                      placeholder="mis. members/foto.jpg (opsional)"
                      onChange={(e) => set('photo_path', e.target.value)}
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <Label>Alamat</Label>
                    <Textarea
                      value={values.address}
                      error={err('address')}
                      placeholder="Opsional"
                      onChange={(e) => set('address', e.target.value)}
                    />
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label>Terdaftar Sejak</Label>
            <Input
              type="date"
              value={values.registered_at}
              error={err('registered_at')}
              onChange={(e) => set('registered_at', e.target.value)}
            />
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={values.status}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as MemberStatus)}
            >
              {(Object.keys(MEMBER_STATUS_LABELS) as MemberStatus[]).map((key) => (
                <option key={key} value={key}>
                  {MEMBER_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Berlaku Dari</Label>
            <Input
              type="date"
              value={values.valid_from}
              error={err('valid_from')}
              onChange={(e) => set('valid_from', e.target.value)}
            />
          </div>
          <div>
            <Label>Berlaku Sampai</Label>
            <Input
              type="date"
              value={values.valid_until}
              error={err('valid_until')}
              onChange={(e) => set('valid_until', e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Catatan</Label>
            <Textarea
              value={values.notes}
              error={err('notes')}
              placeholder="Opsional"
              onChange={(e) => set('notes', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}