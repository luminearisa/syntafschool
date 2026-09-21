import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2, Star, UserPlus, KeyRound } from 'lucide-react';
import { toast } from 'sonner';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Can } from '@/components/common/Can';
import { useOptions } from '@/hooks/useOptions';
import { classesApi } from '@/features/academics/api/academicsApi';
import { studentsApi } from '@/features/students/api/studentsApi';
import { guardiansApi } from '@/features/students/api/guardiansApi';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  Student,
  StudentGuardianInput,
  StudentHistoryStatus,
} from '@/types';

interface Props {
  open: boolean;
  studentId: number | null;
  onClose: () => void;
}

type Tab = 'profil' | 'wali' | 'riwayat';

const HISTORY_STATUS_TONES: Record<StudentHistoryStatus, 'green' | 'amber' | 'red' | 'blue' | 'gray'> = {
  baru: 'blue',
  naik: 'green',
  tinggal: 'amber',
  lulus: 'green',
  pindah: 'gray',
  keluar: 'red',
};

const HISTORY_STATUS_LABELS: Record<StudentHistoryStatus, string> = {
  baru: 'Siswa Baru',
  naik: 'Naik Kelas',
  tinggal: 'Tinggal Kelas',
  lulus: 'Lulus',
  pindah: 'Pindah',
  keluar: 'Keluar',
};

const toId = (value: string | number): number | '' => (value === '' ? '' : Number(value));

function Field({ label, value }: { label: string; value: string | number | null | undefined }) {
  return (
    <div>
      <p className="text-xs text-slate-500">{label}</p>
      <p className="text-sm text-slate-800">{value === null || value === undefined || value === '' ? '-' : value}</p>
    </div>
  );
}

export function StudentDetailModal({ open, studentId, onClose }: Props) {
  const qc = useQueryClient();
  const [tab, setTab] = useState<Tab>('profil');
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});

  const [guardianDraft, setGuardianDraft] = useState<StudentGuardianInput>({
    guardian_id: '',
    full_name: null,
    guardian_relation_id: '',
    is_primary: false,
  });

  const [historyDraft, setHistoryDraft] = useState({
    academic_year_id: '' as number | '',
    school_class_id: '' as number | '',
    status: 'baru' as StudentHistoryStatus,
    note: null as string | null,
  });

  const { options: relationOptions } = useOptions('guardian-relations');
  const { options: yearOptions } = useOptions('academic-years');
  const { options: occupationOptions } = useOptions('occupations');

  const detailQuery = useQuery({
    queryKey: ['student', studentId],
    enabled: open && !!studentId,
    queryFn: () => studentsApi.show(studentId!),
  });

  const student: Student | undefined = detailQuery.data;

  const guardianQuery = useQuery({
    queryKey: ['guardians', 'options'],
    enabled: open,
    staleTime: 1000 * 60 * 5,
    queryFn: () => guardiansApi.list({ all: true }),
  });

  const classQuery = useQuery({
    queryKey: ['classes', 'options', historyDraft.academic_year_id],
    enabled: open && !!historyDraft.academic_year_id,
    queryFn: () =>
      classesApi.list({
        all: true,
        filter: { academic_year_id: historyDraft.academic_year_id as number },
      }),
  });

  useEffect(() => {
    if (!open) return;
    setTab('profil');
    setServerErrors({});
    setGuardianDraft({
      guardian_id: '',
      full_name: null,
      guardian_relation_id: '',
      is_primary: false,
    });
    setHistoryDraft({ academic_year_id: '', school_class_id: '', status: 'baru', note: null });
  }, [open, studentId]);

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['student', studentId] });
    qc.invalidateQueries({ queryKey: ['students'] });
    qc.invalidateQueries({ queryKey: ['guardians'] });
  };

  const onError = (err: unknown) => {
    setServerErrors(getValidationErrors(err) ?? {});
    toast.error(getErrorMessage(err));
  };

  const attachMutation = useMutation({
    mutationFn: (payload: StudentGuardianInput) => studentsApi.attachGuardian(studentId!, payload),
    onSuccess: () => {
      toast.success('Wali ditambahkan');
      setGuardianDraft({
        guardian_id: '',
        full_name: null,
        guardian_relation_id: '',
        is_primary: false,
      });
      setServerErrors({});
      refresh();
    },
    onError,
  });

  const primaryMutation = useMutation({
    mutationFn: (vars: { guardianId: number; relationId: number | '' }) =>
      studentsApi.updateGuardian(studentId!, vars.guardianId, {
        guardian_relation_id: vars.relationId,
        is_primary: true,
      }),
    onSuccess: () => {
      toast.success('Wali utama diperbarui');
      refresh();
    },
    onError,
  });

  const detachMutation = useMutation({
    mutationFn: (guardianId: number) => studentsApi.detachGuardian(studentId!, guardianId),
    onSuccess: () => {
      toast.success('Wali dilepas');
      refresh();
    },
    onError,
  });

  const historyMutation = useMutation({
    mutationFn: () =>
      studentsApi.storeHistory(studentId!, {
        academic_year_id: historyDraft.academic_year_id,
        school_class_id: historyDraft.school_class_id,
        status: historyDraft.status,
        note: historyDraft.note,
      }),
    onSuccess: () => {
      toast.success('Riwayat kelas disimpan');
      setHistoryDraft({ academic_year_id: '', school_class_id: '', status: 'baru', note: null });
      setServerErrors({});
      refresh();
    },
    onError,
  });

  const removeHistoryMutation = useMutation({
    mutationFn: (historyId: number) => studentsApi.removeHistory(studentId!, historyId),
    onSuccess: () => {
      toast.success('Riwayat dihapus');
      refresh();
    },
    onError,
  });

  const accountMutation = useMutation({
    mutationFn: (payload: { email: string; password: string }) =>
      studentsApi.createAccount(studentId!, payload),
    onSuccess: () => {
      toast.success('Akun portal dibuat');
      setServerErrors({});
      refresh();
    },
    onError,
  });

  const [accountDraft, setAccountDraft] = useState({ email: '', password: '' });

  const guardianOptions = useMemo(
    () =>
      (guardianQuery.data?.data ?? []).map((g) => ({
        value: g.id,
        label: g.full_name,
      })),
    [guardianQuery.data],
  );

  const classOptions = useMemo(
    () =>
      (classQuery.data?.data ?? []).map((c) => ({
        value: c.id,
        label: c.name,
      })),
    [classQuery.data],
  );

  const err = (key: string) => serverErrors[key]?.[0];

  const tabs: { key: Tab; label: string; count?: number }[] = [
    { key: 'profil', label: 'Profil' },
    { key: 'wali', label: 'Wali Murid', count: student?.guardians?.length },
    { key: 'riwayat', label: 'Riwayat Kelas', count: student?.histories?.length },
  ];

  return (
    <Modal open={open} onClose={onClose} title="Detail Siswa" size="xl">
      {detailQuery.isLoading || !student ? (
        <div className="flex justify-center py-16">
          <Spinner />
        </div>
      ) : (
        <div className="space-y-4">
          <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{student.full_name}</h3>
                <p className="text-sm text-slate-500">
                  NIS {student.nis}
                  {student.nisn ? ` · NISN ${student.nisn}` : ''}
                  {student.age ? ` · ${student.age} tahun` : ''}
                </p>
              </div>
              <div className="flex flex-wrap items-center gap-1.5">
                {student.current_class && <Badge tone="brand">{student.current_class.name}</Badge>}
                {student.status && <Badge tone="blue">{student.status.name}</Badge>}
                {student.is_active ? <Badge tone="green">Aktif</Badge> : <Badge tone="red">Nonaktif</Badge>}
                {student.has_account && <Badge tone="amber">Punya Akun</Badge>}
              </div>
            </div>
          </div>

          <div className="flex gap-1 border-b border-slate-200">
            {tabs.map((t) => (
              <button
                key={t.key}
                type="button"
                onClick={() => setTab(t.key)}
                className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                  tab === t.key
                    ? 'border-brand-600 text-brand-700'
                    : 'border-transparent text-slate-500 hover:text-slate-700'
                }`}
              >
                {t.label}
                {typeof t.count === 'number' && (
                  <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
                    {t.count}
                  </span>
                )}
              </button>
            ))}
          </div>

          {tab === 'profil' && (
            <div className="space-y-5">
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <Field label="Jenis Kelamin" value={student.gender?.name} />
                <Field label="Agama" value={student.religion?.name} />
                <Field label="Golongan Darah" value={student.blood_type} />
                <Field label="Tempat Lahir" value={student.birth_place} />
                <Field label="Tanggal Lahir" value={student.birth_date} />
                <Field label="Telepon" value={student.phone} />
                <Field label="Email" value={student.email} />
                <Field label="Tempat Tinggal" value={student.residence_type?.name} />
                <Field label="Transportasi" value={student.transportation?.name} />
                <Field label="Anak Ke-" value={student.child_order} />
                <Field label="Jumlah Saudara" value={student.sibling_count} />
                <Field label="Asal Sekolah" value={student.previous_school} />
                <Field label="Tanggal Masuk" value={student.admission_date} />
                <Field label="Sekolah" value={student.school?.name} />
              </div>

              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Alamat
                </p>
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                  <Field label="Jalan" value={student.address} />
                  <Field label="Provinsi" value={student.province?.name} />
                  <Field label="Kabupaten/Kota" value={student.city?.name} />
                  <Field label="Kecamatan" value={student.district?.name} />
                  <Field label="Desa/Kelurahan" value={student.village?.name} />
                  <Field label="Kode Pos" value={student.postal_code} />
                </div>
              </div>

              <Can perm="student.update">
                {!student.has_account && (
                  <div className="rounded-lg border border-slate-200 p-3">
                    <p className="mb-2 flex items-center gap-2 text-sm font-medium text-slate-700">
                      <KeyRound className="h-4 w-4" /> Buat Akun Portal Siswa
                    </p>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <div>
                        <Label>Email</Label>
                        <Input
                          type="email"
                          value={accountDraft.email}
                          error={err('email')}
                          onChange={(e) => setAccountDraft((p) => ({ ...p, email: e.target.value }))}
                        />
                      </div>
                      <div>
                        <Label>Kata Sandi</Label>
                        <Input
                          type="password"
                          value={accountDraft.password}
                          error={err('password')}
                          onChange={(e) =>
                            setAccountDraft((p) => ({ ...p, password: e.target.value }))
                          }
                        />
                      </div>
                    </div>
                    <div className="mt-3">
                      <Button
                        size="sm"
                        loading={accountMutation.isPending}
                        onClick={() => accountMutation.mutate(accountDraft)}
                      >
                        Buat Akun
                      </Button>
                    </div>
                  </div>
                )}
              </Can>
            </div>
          )}

          {tab === 'wali' && (
            <div className="space-y-4">
              {(student.guardians ?? []).length === 0 ? (
                <p className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-xs text-slate-400">
                  Belum ada wali terhubung.
                </p>
              ) : (
                <div className="divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200">
                  {(student.guardians ?? []).map((g) => (
                    <div key={g.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
                      <div>
                        <p className="flex items-center gap-2 text-sm font-medium text-slate-800">
                          {g.is_primary && <Star className="h-3.5 w-3.5 text-amber-500" />}
                          {g.full_name}
                        </p>
                        <p className="text-xs text-slate-500">
                          {g.occupation?.name ?? 'Pekerjaan belum diisi'}
                          {g.phone ? ` · ${g.phone}` : ''}
                        </p>
                      </div>
                      <div className="flex items-center gap-1.5">
                        {g.is_primary ? (
                          <Badge tone="amber">Wali Utama</Badge>
                        ) : (
                          <Can perm="student.update">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() =>
                                primaryMutation.mutate({
                                  guardianId: g.id,
                                  relationId: g.guardian_relation_id ?? '',
                                })
                              }
                            >
                              Jadikan Utama
                            </Button>
                          </Can>
                        )}
                        <Can perm="student.update">
                          <Button
                            variant="ghost"
                            size="icon"
                            className="text-red-600 hover:bg-red-50"
                            title="Lepas wali"
                            onClick={() => detachMutation.mutate(g.id)}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </Can>
                      </div>
                    </div>
                  ))}
                </div>
              )}

              <Can perm="student.update">
                <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                  <p className="mb-3 flex items-center gap-2 text-sm font-medium text-slate-700">
                    <UserPlus className="h-4 w-4" /> Tambah Wali
                  </p>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                      <Label>Wali Terdaftar</Label>
                      <SearchSelect
                        options={guardianOptions}
                        value={guardianDraft.guardian_id ?? ''}
                        onChange={(v) => {
                          const id = toId(v);
                          const picked = guardianQuery.data?.data.find((x) => x.id === id);
                          setGuardianDraft((p) => ({
                            ...p,
                            guardian_id: id,
                            full_name: picked?.full_name ?? null,
                          }));
                        }}
                        placeholder="-- Pilih --"
                      />
                      {err('guardian_id') && (
                        <p className="mt-1 text-xs text-red-600">{err('guardian_id')}</p>
                      )}
                    </div>
                    <div>
                      <Label>Atau Nama Baru</Label>
                      <Input
                        value={guardianDraft.full_name ?? ''}
                        disabled={!!guardianDraft.guardian_id}
                        placeholder="Contoh: Bapak Santoso"
                        onChange={(e) =>
                          setGuardianDraft((p) => ({ ...p, full_name: e.target.value || null }))
                        }
                      />
                    </div>
                    <div>
                      <Label>Hubungan</Label>
                      <SearchSelect
                        options={relationOptions}
                        value={guardianDraft.guardian_relation_id ?? ''}
                        onChange={(v) =>
                          setGuardianDraft((p) => ({ ...p, guardian_relation_id: toId(v) }))
                        }
                        placeholder="-- Pilih --"
                      />
                    </div>
                    <div>
                      <Label>Pekerjaan</Label>
                      <SearchSelect
                        options={occupationOptions}
                        value={guardianDraft.occupation_id ?? ''}
                        onChange={(v) => setGuardianDraft((p) => ({ ...p, occupation_id: toId(v) }))}
                        placeholder="-- Pilih --"
                      />
                    </div>
                    <div>
                      <Label>Telepon</Label>
                      <Input
                        value={guardianDraft.phone ?? ''}
                        onChange={(e) =>
                          setGuardianDraft((p) => ({ ...p, phone: e.target.value || null }))
                        }
                      />
                    </div>
                    <div className="flex items-end gap-3">
                      <label className="flex cursor-pointer items-center gap-2 pb-2 text-sm text-slate-700">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                          checked={guardianDraft.is_primary}
                          onChange={(e) =>
                            setGuardianDraft((p) => ({ ...p, is_primary: e.target.checked }))
                          }
                        />
                        Wali utama
                      </label>
                    </div>
                  </div>
                  <div className="mt-3">
                    <Button
                      size="sm"
                      loading={attachMutation.isPending}
                      onClick={() => attachMutation.mutate(guardianDraft)}
                    >
                      <Plus className="h-3.5 w-3.5" /> Tambahkan
                    </Button>
                  </div>
                </div>
              </Can>
            </div>
          )}

          {tab === 'riwayat' && (
            <div className="space-y-4">
              {(student.histories ?? []).length === 0 ? (
                <p className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-xs text-slate-400">
                  Belum ada riwayat kelas.
                </p>
              ) : (
                <div className="overflow-hidden rounded-lg border border-slate-200">
                  <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                      <tr>
                        <th className="px-3 py-2 font-medium">Tahun Ajaran</th>
                        <th className="px-3 py-2 font-medium">Kelas</th>
                        <th className="px-3 py-2 font-medium">Status</th>
                        <th className="px-3 py-2 font-medium">Catatan</th>
                        <th className="px-3 py-2" />
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {(student.histories ?? []).map((h) => (
                        <tr key={h.id}>
                          <td className="px-3 py-2 text-slate-700">
                            {h.academic_year?.name ?? '-'}
                          </td>
                          <td className="px-3 py-2 text-slate-700">{h.class_name ?? '-'}</td>
                          <td className="px-3 py-2">
                            <Badge tone={HISTORY_STATUS_TONES[h.status] ?? 'gray'}>
                              {HISTORY_STATUS_LABELS[h.status] ?? h.status}
                            </Badge>
                          </td>
                          <td className="px-3 py-2 text-xs text-slate-500">{h.note ?? '-'}</td>
                          <td className="px-3 py-2 text-right">
                            <Can perm="student.update">
                              <Button
                                variant="ghost"
                                size="icon"
                                className="text-red-600 hover:bg-red-50"
                                title="Hapus riwayat"
                                onClick={() => removeHistoryMutation.mutate(h.id)}
                              >
                                <Trash2 className="h-4 w-4" />
                              </Button>
                            </Can>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <Can perm="student.update">
                <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                  <p className="mb-3 text-sm font-medium text-slate-700">
                    Simpan Riwayat Kelas
                  </p>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                      <Label required>Tahun Ajaran</Label>
                      <SearchSelect
                        options={yearOptions}
                        value={historyDraft.academic_year_id}
                        onChange={(v) =>
                          setHistoryDraft((p) => ({
                            ...p,
                            academic_year_id: toId(v),
                            school_class_id: '',
                          }))
                        }
                        placeholder="-- Pilih --"
                      />
                      {err('academic_year_id') && (
                        <p className="mt-1 text-xs text-red-600">{err('academic_year_id')}</p>
                      )}
                    </div>
                    <div>
                      <Label>Kelas</Label>
                      <SearchSelect
                        options={classOptions}
                        value={historyDraft.school_class_id}
                        disabled={!historyDraft.academic_year_id}
                        onChange={(v) =>
                          setHistoryDraft((p) => ({ ...p, school_class_id: toId(v) }))
                        }
                        placeholder={
                          historyDraft.academic_year_id ? '-- Pilih Kelas --' : 'Pilih tahun ajaran dulu'
                        }
                      />
                      {err('school_class_id') && (
                        <p className="mt-1 text-xs text-red-600">{err('school_class_id')}</p>
                      )}
                    </div>
                    <div>
                      <Label required>Status</Label>
                      <Select
                        value={historyDraft.status}
                        onChange={(e) =>
                          setHistoryDraft((p) => ({
                            ...p,
                            status: e.target.value as StudentHistoryStatus,
                          }))
                        }
                      >
                        {(Object.keys(HISTORY_STATUS_LABELS) as StudentHistoryStatus[]).map((s) => (
                          <option key={s} value={s}>
                            {HISTORY_STATUS_LABELS[s]}
                          </option>
                        ))}
                      </Select>
                    </div>
                    <div className="sm:col-span-3">
                      <Label>Catatan</Label>
                      <Input
                        value={historyDraft.note ?? ''}
                        placeholder="Opsional"
                        onChange={(e) =>
                          setHistoryDraft((p) => ({ ...p, note: e.target.value || null }))
                        }
                      />
                    </div>
                  </div>
                  <div className="mt-3 flex items-center gap-2">
                    <Button
                      size="sm"
                      loading={historyMutation.isPending}
                      disabled={!historyDraft.academic_year_id}
                      onClick={() => historyMutation.mutate()}
                    >
                      Simpan Riwayat
                    </Button>
                    <span className="text-xs text-slate-400">
                      Mengisi kelas akan sekaligus mendaftarkan siswa ke rombel tahun tersebut.
                    </span>
                  </div>
                </div>
              </Can>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
