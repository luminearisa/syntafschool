import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import {
  ConfigFormModal,
  Tabs,
  defaultValues,
  fmtDate,
  type FormFieldConfig,
  type FormValues,
} from '../components/BoardingUi';
import { activitiesApi, attendanceTypesApi, schedulesApi } from '../api/boardingApi';
import { useActivityOptions, useAttendanceTypeOptions, useDormitoryOptions, useGroupOptions } from '../hooks/useBoardingOptions';
import {
  ACTIVITY_CATEGORY_LABELS,
  ATTENDANCE_CATEGORY_LABELS,
  DAY_LABELS,
  toOptions,
} from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  BoardingActivity,
  BoardingActivitySchedule,
  BoardingAttendanceType,
} from '@/types/boarding';

function TypeTab() {
  const qc = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<BoardingAttendanceType | null>(null);
  const [values, setValues] = useState<FormValues>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const fields: FormFieldConfig[] = [
    { name: 'code', label: 'Kode', required: true },
    { name: 'name', label: 'Nama', required: true },
    { name: 'category', label: 'Kategori', type: 'select', options: toOptions(ATTENDANCE_CATEGORY_LABELS), required: true },
    { name: 'color', label: 'Warna (hex)', placeholder: '#22c55e' },
    { name: 'is_present', label: 'Dihitung Hadir', type: 'checkbox' },
    { name: 'is_excused', label: 'Ada Keterangan', type: 'checkbox' },
    { name: 'requires_note', label: 'Wajib Catatan', type: 'checkbox' },
    { name: 'requires_approval', label: 'Butuh Persetujuan', type: 'checkbox' },
    { name: 'is_active', label: 'Aktif', type: 'checkbox' },
  ];

  const listQuery = useQuery({
    queryKey: ['boarding', 'attendance-types'],
    queryFn: () => attendanceTypesApi.list({ all: 1 }),
  });

  const save = useMutation({
    mutationFn: (payload: FormValues) =>
      editing ? attendanceTypesApi.update(editing.id, payload as never) : attendanceTypesApi.create(payload as never),
    onSuccess: () => {
      toast.success('Tipe absensi disimpan');
      setFormOpen(false);
      setEditing(null);
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => attendanceTypesApi.remove(id),
    onSuccess: () => {
      toast.success('Tipe absensi dihapus');
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = (listQuery.data?.rows ?? []) as BoardingAttendanceType[];

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <Can perm="boarding.activity.manage">
          <Button onClick={() => {
            setEditing(null);
            setErrors({});
            setValues(defaultValues(fields, { is_active: true, is_present: true }));
            setFormOpen(true);
          }}>
            <Plus className="h-4 w-4" /> Tambah Tipe Absensi
          </Button>
        </Can>
      </div>
      {listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-12 text-slate-400"><Spinner /> Memuat...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada tipe absensi" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Kategori</th>
                  <th className="px-4 py-2 font-medium">Sifat</th>
                  <th className="px-4 py-2 font-medium">Aktif</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((t) => (
                  <tr key={t.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2 font-mono text-xs">{t.code}</td>
                    <td className="px-4 py-2 font-medium text-slate-800">
                      <span className="mr-2 inline-block h-2 w-2 rounded-full" style={{ background: t.color ?? '#94a3b8' }} />
                      {t.name}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{t.category_label}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {[
                        t.is_present && 'Hadir',
                        t.is_excused && 'Keterangan',
                        t.requires_note && 'Catatan wajib',
                        t.requires_approval && 'Persetujuan',
                      ].filter(Boolean).join(' • ') || '—'}
                    </td>
                    <td className="px-4 py-2"><Badge tone={t.is_active ? 'green' : 'gray'}>{t.is_active ? 'Ya' : 'Tidak'}</Badge></td>
                    <td className="px-4 py-2">
                      <Can perm="boarding.activity.manage">
                        <div className="flex gap-1">
                          <Button variant="ghost" size="icon" onClick={() => {
                            setEditing(t);
                            setErrors({});
                            setValues(defaultValues(fields, t as unknown as Record<string, unknown>));
                            setFormOpen(true);
                          }}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button variant="ghost" size="icon" onClick={() => remove.mutate(t.id)}>
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </div>
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
      <ConfigFormModal
        open={formOpen}
        title={editing ? 'Edit Tipe Absensi' : 'Tambah Tipe Absensi'}
        fields={fields}
        values={values}
        submitting={save.isPending}
        serverErrors={errors}
        onChange={setValues}
        onClose={() => { setFormOpen(false); setEditing(null); }}
        onSubmit={() => save.mutate(values)}
      />
    </div>
  );
}

function ActivityTab() {
  const qc = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<BoardingActivity | null>(null);
  const [values, setValues] = useState<FormValues>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { options: typeOptions } = useAttendanceTypeOptions();

  const fields: FormFieldConfig[] = [
    { name: 'code', label: 'Kode', required: true },
    { name: 'name', label: 'Nama Kegiatan', required: true },
    { name: 'category', label: 'Kategori', type: 'select', options: toOptions(ACTIVITY_CATEGORY_LABELS), required: true },
    { name: 'default_attendance_type_id', label: 'Tipe Absensi Default', type: 'search-select', options: typeOptions },
    { name: 'late_after_minutes', label: 'Terlambat Setelah (menit)', type: 'number' },
    { name: 'is_required', label: 'Wajib diikuti', type: 'checkbox' },
    { name: 'is_active', label: 'Aktif', type: 'checkbox' },
    { name: 'description', label: 'Deskripsi', type: 'textarea' },
  ];

  const listQuery = useQuery({
    queryKey: ['boarding', 'activities'],
    queryFn: () => activitiesApi.list({ all: 1 }),
  });

  const save = useMutation({
    mutationFn: (payload: FormValues) =>
      editing ? activitiesApi.update(editing.id, payload as never) : activitiesApi.create(payload as never),
    onSuccess: () => {
      toast.success('Kegiatan disimpan');
      setFormOpen(false);
      setEditing(null);
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => activitiesApi.remove(id),
    onSuccess: () => {
      toast.success('Kegiatan dihapus');
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = (listQuery.data?.rows ?? []) as BoardingActivity[];

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <Can perm="boarding.activity.manage">
          <Button onClick={() => {
            setEditing(null);
            setErrors({});
            setValues(defaultValues(fields, { is_active: true }));
            setFormOpen(true);
          }}>
            <Plus className="h-4 w-4" /> Tambah Kegiatan
          </Button>
        </Can>
      </div>
      {listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-12 text-slate-400"><Spinner /> Memuat...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada kegiatan asrama" /></Card>
      ) : (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((a) => (
            <Card key={a.id} className="p-4">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-sm font-semibold text-slate-800">{a.name}</p>
                  <p className="text-xs text-slate-500">{a.code} • {a.category_label}</p>
                </div>
                <Badge tone={a.is_active ? 'green' : 'gray'}>{a.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
              </div>
              <p className="mt-2 text-xs text-slate-500">
                Default: {a.default_attendance_type?.name ?? '—'} • Jadwal: {a.schedules_count} •{' '}
                {a.is_required ? 'Wajib' : 'Opsional'}
              </p>
              <div className="mt-3 flex gap-1">
                <Can perm="boarding.activity.manage">
                  <Button size="sm" variant="ghost" onClick={() => {
                    setEditing(a);
                    setErrors({});
                    setValues(defaultValues(fields, a as unknown as Record<string, unknown>));
                    setFormOpen(true);
                  }}>
                    <Pencil className="h-3.5 w-3.5" /> Edit
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => remove.mutate(a.id)}>
                    <Trash2 className="h-3.5 w-3.5 text-red-500" />
                  </Button>
                </Can>
              </div>
            </Card>
          ))}
        </div>
      )}
      <ConfigFormModal
        open={formOpen}
        title={editing ? 'Edit Kegiatan' : 'Tambah Kegiatan'}
        fields={fields}
        values={values}
        submitting={save.isPending}
        serverErrors={errors}
        onChange={setValues}
        onClose={() => { setFormOpen(false); setEditing(null); }}
        onSubmit={() => save.mutate(values)}
      />
    </div>
  );
}

function ScheduleTab() {
  const qc = useQueryClient();
  const [view, setView] = useState<'weekly' | 'list'>('weekly');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<BoardingActivitySchedule | null>(null);
  const [values, setValues] = useState<FormValues>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { options: activityOptions } = useActivityOptions();
  const { options: dormitoryOptions } = useDormitoryOptions();
  const { options: groupOptions } = useGroupOptions();

  const fields: FormFieldConfig[] = [
    { name: 'activity_id', label: 'Kegiatan', type: 'search-select', options: activityOptions, required: true },
    {
      name: 'day_of_week',
      label: 'Hari',
      type: 'select',
      required: true,
      options: Object.entries(DAY_LABELS).map(([d, l]) => ({ value: Number(d), label: l })),
    },
    { name: 'start_time', label: 'Jam Mulai', type: 'time', required: true },
    { name: 'end_time', label: 'Jam Selesai', type: 'time', required: true },
    { name: 'late_cutoff', label: 'Batas Terlambat', type: 'time' },
    { name: 'dormitory_id', label: 'Khusus Asrama', type: 'search-select', options: dormitoryOptions },
    { name: 'group_id', label: 'Khusus Kelompok', type: 'search-select', options: groupOptions },
    { name: 'effective_start', label: 'Efektif Mulai', type: 'date' },
    { name: 'effective_end', label: 'Efektif Selesai', type: 'date' },
    { name: 'is_active', label: 'Aktif', type: 'checkbox' },
    { name: 'notes', label: 'Catatan', type: 'textarea' },
  ];

  const weeklyQuery = useQuery({
    queryKey: ['boarding', 'schedules', 'weekly'],
    queryFn: () => schedulesApi.weekly(),
  });

  const listQuery = useQuery({
    queryKey: ['boarding', 'schedules', 'list'],
    queryFn: () => schedulesApi.list({ per_page: 100 }),
  });

  const save = useMutation({
    mutationFn: (payload: FormValues) =>
      editing ? schedulesApi.update(editing.id, payload as never) : schedulesApi.create(payload as never),
    onSuccess: () => {
      toast.success('Jadwal disimpan');
      setFormOpen(false);
      setEditing(null);
      qc.invalidateQueries({ queryKey: ['boarding', 'schedules'] });
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => schedulesApi.remove(id),
    onSuccess: () => {
      toast.success('Jadwal dihapus');
      qc.invalidateQueries({ queryKey: ['boarding', 'schedules'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const openEdit = (s: BoardingActivitySchedule) => {
    setEditing(s);
    setErrors({});
    setValues(defaultValues(fields, s as unknown as Record<string, unknown>));
    setFormOpen(true);
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <div className="flex gap-1 rounded-md bg-slate-100 p-0.5">
          <Button size="sm" variant={view === 'weekly' ? 'primary' : 'ghost'} onClick={() => setView('weekly')}>
            Mingguan
          </Button>
          <Button size="sm" variant={view === 'list' ? 'primary' : 'ghost'} onClick={() => setView('list')}>
            Daftar
          </Button>
        </div>
        <Can perm="boarding.schedule.manage">
          <Button onClick={() => {
            setEditing(null);
            setErrors({});
            setValues(defaultValues(fields, { is_active: true }));
            setFormOpen(true);
          }}>
            <Plus className="h-4 w-4" /> Tambah Jadwal
          </Button>
        </Can>
      </div>

      {view === 'weekly' ? (
        weeklyQuery.isLoading ? (
          <Card><div className="flex items-center justify-center gap-2 py-12 text-slate-400"><Spinner /> Memuat jadwal...</div></Card>
        ) : (
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            {(weeklyQuery.data ?? []).map((day) => (
              <Card key={day.day} className="p-3">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{day.day_label}</p>
                {day.schedules.length === 0 ? (
                  <p className="text-xs text-slate-400">Tidak ada kegiatan</p>
                ) : (
                  <ul className="space-y-2">
                    {day.schedules.map((s) => (
                      <li key={s.id} className="rounded-md border border-slate-100 p-2 text-xs">
                        <div className="flex items-center justify-between">
                          <span className="font-medium text-slate-800">{s.activity?.name ?? '—'}</span>
                          <Can perm="boarding.schedule.manage">
                            <div className="flex gap-0.5">
                              <button className="text-slate-400 hover:text-slate-700" onClick={() => openEdit(s)}>
                                <Pencil className="h-3 w-3" />
                              </button>
                              <button className="text-red-400 hover:text-red-600" onClick={() => remove.mutate(s.id)}>
                                <Trash2 className="h-3 w-3" />
                              </button>
                            </div>
                          </Can>
                        </div>
                        <p className="text-slate-500">
                          {String(s.start_time).slice(0, 5)}–{String(s.end_time).slice(0, 5)}
                          {s.dormitory ? ` • ${s.dormitory.name}` : ''}
                          {s.group ? ` • ${s.group.name}` : ''}
                        </p>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            ))}
          </div>
        )
      ) : listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-12 text-slate-400"><Spinner /> Memuat...</div></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kegiatan</th>
                  <th className="px-4 py-2 font-medium">Hari</th>
                  <th className="px-4 py-2 font-medium">Jam</th>
                  <th className="px-4 py-2 font-medium">Sasaran</th>
                  <th className="px-4 py-2 font-medium">Berlaku</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {((listQuery.data?.rows ?? []) as BoardingActivitySchedule[]).map((s) => (
                  <tr key={s.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2 font-medium text-slate-800">{s.activity?.name ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-600">{s.day_label}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {String(s.start_time).slice(0, 5)}–{String(s.end_time).slice(0, 5)}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {s.dormitory?.name ?? s.group?.name ?? 'Semua santri'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {s.effective_start ? `${fmtDate(s.effective_start)} — ${fmtDate(s.effective_end)}` : 'Sepanjang tahun'}
                    </td>
                    <td className="px-4 py-2">
                      <Can perm="boarding.schedule.manage">
                        <div className="flex gap-1">
                          <Button variant="ghost" size="icon" onClick={() => openEdit(s)}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button variant="ghost" size="icon" onClick={() => remove.mutate(s.id)}>
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </div>
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <ConfigFormModal
        open={formOpen}
        title={editing ? 'Edit Jadwal Kegiatan' : 'Tambah Jadwal'}
        fields={fields}
        values={values}
        submitting={save.isPending}
        serverErrors={errors}
        onChange={setValues}
        onClose={() => { setFormOpen(false); setEditing(null); }}
        onSubmit={() => save.mutate(values)}
      />
    </div>
  );
}

export function BoardingActivitiesPage() {
  const [tab, setTab] = useState('activities');
  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Kegiatan & Jadwal Asrama</h1>
        <p className="text-sm text-slate-500">
          Kegiatan harian (sholat, sekolah, tahfidz), tipe absensi configurable, dan jadwal mingguan.
        </p>
      </div>
      <Tabs
        tabs={[
          { key: 'activities', label: 'Kegiatan' },
          { key: 'types', label: 'Tipe Absensi' },
          { key: 'schedules', label: 'Jadwal Mingguan' },
        ]}
        active={tab}
        onChange={setTab}
      />
      {tab === 'activities' && <ActivityTab />}
      {tab === 'types' && <TypeTab />}
      {tab === 'schedules' && <ScheduleTab />}
    </div>
  );
}
