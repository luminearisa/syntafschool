import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Clock3, Plus, RefreshCw, ShieldCheck, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';
import { religiousApi } from '../api/religiousApi';
import type { ReligiousPrayerWindow } from '@/types/religious';
import {
  displayStatus,
  ErrorCard,
  FormField,
  localDate,
  localDateTime,
  LoadingCard,
  MetricCard,
  prayerLabel,
  ReligiousPageHeader,
  statusTone,
} from '../components/ReligiousUi';

interface AttendanceFormState {
  prayer_type: string;
  participant_type: 'STUDENT' | 'EMPLOYEE';
  student_id: string;
  employee_id: string;
  mosque_id: string;
  campus_id: string;
  prayer_schedule_id: string;
  status: string;
  recorded_at: string;
  notes: string;
  authorized: boolean;
  authorization_reason: string;
}

const DEFAULT_PRAYERS = [
  { code: 'FAJR', name: 'Subuh' },
  { code: 'DHUHR', name: 'Dzuhur' },
  { code: 'ASR', name: 'Ashar' },
  { code: 'MAGHRIB', name: 'Maghrib' },
  { code: 'ISHA', name: 'Isya' },
];
const STATUSES = ['PRESENT', 'LATE', 'ABSENT', 'EXCUSED', 'AUTHORIZED_ABSENCE', 'UNKNOWN'];

function initialForm(date: string, prayerType = 'FAJR'): AttendanceFormState {
  return {
    prayer_type: prayerType,
    participant_type: 'STUDENT',
    student_id: '',
    employee_id: '',
    mosque_id: '',
    campus_id: '',
    prayer_schedule_id: '',
    status: '',
    recorded_at: `${date}T${localDateTime().slice(11)}`,
    notes: '',
    authorized: false,
    authorization_reason: '',
  };
}

export function ReligiousAttendancePage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canApprove = can('religious.attendance.approve');
  const [date, setDate] = useState(localDate());
  const [prayerFilter, setPrayerFilter] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState<AttendanceFormState>(() => initialForm(localDate()));
  const [windowResult, setWindowResult] = useState<ReligiousPrayerWindow | null>(null);

  const prayerTypesQuery = useQuery({
    queryKey: ['religious', 'prayer-types'],
    queryFn: religiousApi.prayerTypes,
    enabled: can('religious.prayer.view'),
  });
  const mosqueQuery = useQuery({
    queryKey: ['religious', 'mosques', 'attendance-options'],
    queryFn: () => religiousApi.listMosques({}),
    enabled: can('religious.mosque.view'),
  });
  const statsQuery = useQuery({
    queryKey: ['religious', 'attendance-statistics', date, prayerFilter],
    queryFn: () => religiousApi.attendanceStatistics({ date, prayer_type: prayerFilter || undefined }),
  });
  const dashboardQuery = useQuery({
    queryKey: ['religious', 'attendance-dashboard', date, prayerFilter],
    queryFn: () => religiousApi.attendanceDashboard({ date, prayer_type: prayerFilter || undefined }),
  });

  const prayerTypes = useMemo(
    () => prayerTypesQuery.data?.length ? prayerTypesQuery.data : DEFAULT_PRAYERS,
    [prayerTypesQuery.data],
  );
  const prayerNames = Object.fromEntries(prayerTypes.map((prayer) => [prayer.code, prayer.name]));
  const byPrayer = Object.entries(dashboardQuery.data?.by_prayer ?? statsQuery.data?.by_prayer ?? {});

  const windowMutation = useMutation({
    mutationFn: (payload: Parameters<typeof religiousApi.attendanceWindow>[0]) => religiousApi.attendanceWindow(payload),
    onSuccess: (result) => {
      setWindowResult(result);
      if (result.allowed) toast.success(result.status_hint ? `Jendela presensi terbuka · ${displayStatus(result.status_hint)}` : 'Jendela presensi terbuka');
      else toast.warning(result.reason ?? 'Presensi berada di luar jendela waktu.');
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const recordMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => religiousApi.recordPrayerAttendance(payload),
    onSuccess: (result) => {
      toast.success(result.duplicate ? 'Presensi sudah pernah dicatat; duplikat tidak dibuat.' : 'Presensi shalat berhasil dicatat.');
      setFormOpen(false);
      setWindowResult(null);
      queryClient.invalidateQueries({ queryKey: ['religious', 'attendance-statistics'] });
      queryClient.invalidateQueries({ queryKey: ['religious', 'attendance-dashboard'] });
      queryClient.invalidateQueries({ queryKey: ['religious', 'dashboard'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const updateForm = (next: Partial<AttendanceFormState>) => {
    setForm((current) => ({ ...current, ...next }));
    setWindowResult(null);
  };

  const checkWindow = () => {
    if (!form.prayer_type) {
      toast.error('Pilih jenis shalat terlebih dahulu.');
      return;
    }
    windowMutation.mutate({
      prayer_type: form.prayer_type,
      at: form.recorded_at ? form.recorded_at.replace('T', ' ') : undefined,
      mosque_id: form.mosque_id ? Number(form.mosque_id) : undefined,
      campus_id: form.campus_id ? Number(form.campus_id) : undefined,
      prayer_schedule_id: form.prayer_schedule_id ? Number(form.prayer_schedule_id) : undefined,
    });
  };

  const submitAttendance = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (form.participant_type === 'STUDENT' && !form.student_id) {
      toast.error('ID siswa wajib diisi.');
      return;
    }
    if (form.participant_type === 'EMPLOYEE' && !form.employee_id) {
      toast.error('ID pegawai wajib diisi.');
      return;
    }
    if (windowResult && !windowResult.allowed) {
      if (!windowResult.overrideable) {
        toast.error(windowResult.reason ?? 'Presensi tidak dapat dicatat untuk jendela ini.');
        return;
      }
      if (!canApprove || !form.authorized || form.authorization_reason.trim().length < 5) {
        toast.error('Override membutuhkan izin persetujuan dan alasan minimal 5 karakter.');
        return;
      }
    }

    recordMutation.mutate({
      prayer_type: form.prayer_type,
      attendance_date: date,
      participant_type: form.participant_type,
      student_id: form.participant_type === 'STUDENT' ? Number(form.student_id) : undefined,
      employee_id: form.participant_type === 'EMPLOYEE' ? Number(form.employee_id) : undefined,
      mosque_id: form.mosque_id ? Number(form.mosque_id) : undefined,
      campus_id: form.campus_id ? Number(form.campus_id) : undefined,
      prayer_schedule_id: form.prayer_schedule_id ? Number(form.prayer_schedule_id) : undefined,
      status: form.status || undefined,
      method: 'MANUAL',
      recorded_at: form.recorded_at || undefined,
      notes: form.notes || undefined,
      authorized: form.authorized || undefined,
      authorization_reason: form.authorized ? form.authorization_reason : undefined,
    });
  };

  const openForm = () => {
    setForm(initialForm(date, prayerFilter || prayerTypes[0]?.code));
    setWindowResult(null);
    setFormOpen(true);
  };

  const summary = statsQuery.data;

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Presensi shalat"
        description="Catat kehadiran operasional dengan pemeriksaan jendela waktu. Setiap override membutuhkan hak persetujuan dan alasan yang tercatat."
        actions={
          <>
            <Button
              variant="outline"
              size="icon"
              aria-label="Muat ulang statistik"
              title="Muat ulang statistik"
              onClick={() => {
                statsQuery.refetch();
                dashboardQuery.refetch();
              }}
            >
              <RefreshCw className={statsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="religious.attendance.record">
              <Button onClick={openForm}><Plus className="h-4 w-4" /> Catat presensi</Button>
            </Can>
          </>
        }
      />

      <Card>
        <CardBody className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end">
          <FormField label="Tanggal">
            <Input type="date" value={date} onChange={(event) => setDate(event.target.value)} />
          </FormField>
          <FormField label="Jenis shalat">
            <Select value={prayerFilter} onChange={(event) => setPrayerFilter(event.target.value)}>
              <option value="">Semua shalat</option>
              {prayerTypes.map((prayer) => <option key={prayer.code} value={prayer.code}>{prayer.name}</option>)}
            </Select>
          </FormField>
          <div className="rounded-lg bg-emerald-50 px-3 py-2 text-xs leading-5 text-emerald-800">
            Statistik hanya merangkum catatan presensi dan tidak digunakan untuk menyimpulkan karakter siswa.
          </div>
          <div className="flex items-center gap-2 text-xs text-slate-500">
            <ShieldCheck className="h-4 w-4 text-emerald-700" /> Pemeriksaan sisi server aktif
          </div>
        </CardBody>
      </Card>

      {statsQuery.isLoading ? (
        <LoadingCard label="Memuat statistik presensi..." />
      ) : statsQuery.isError ? (
        <ErrorCard message={getErrorMessage(statsQuery.error)} onRetry={() => statsQuery.refetch()} />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard label="Total catatan" value={summary?.total ?? 0} icon={<Users className="h-5 w-5" />} tone="blue" />
            <MetricCard label="Hadir" value={summary?.present ?? 0} icon={<CheckCircle2 className="h-5 w-5" />} />
            <MetricCard label="Terlambat" value={summary?.late ?? 0} icon={<Clock3 className="h-5 w-5" />} tone="amber" />
            <MetricCard label="Tingkat kehadiran" value={summary?.attendance_rate == null ? '—' : `${summary.attendance_rate}%`} detail={`${summary?.absent ?? 0} tidak hadir · ${summary?.excused ?? 0} izin`} tone="green" />
          </div>

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-[1.35fr_1fr]">
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Rincian per shalat</CardTitle>
              </CardHeader>
              {byPrayer.length === 0 ? (
                <CardBody><p className="text-sm text-slate-500">Belum ada data presensi untuk filter ini.</p></CardBody>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[540px] text-sm">
                    <thead><tr className="border-y border-slate-100 bg-slate-50/70 text-left text-xs uppercase tracking-wide text-slate-400">
                      <th className="px-5 py-3 font-medium">Shalat</th><th className="px-3 py-3 text-right font-medium">Total</th><th className="px-3 py-3 text-right font-medium">Hadir</th><th className="px-3 py-3 text-right font-medium">Terlambat</th><th className="px-5 py-3 text-right font-medium">Tidak hadir / izin</th>
                    </tr></thead>
                    <tbody>
                      {byPrayer.map(([code, row]) => (
                        <tr key={code} className="border-b border-slate-50 last:border-0">
                          <td className="px-5 py-3 font-medium text-slate-800">{prayerLabel(code, prayerNames)}</td>
                          <td className="px-3 py-3 text-right text-slate-600">{row.total ?? 0}</td>
                          <td className="px-3 py-3 text-right text-emerald-700">{row.present ?? 0}</td>
                          <td className="px-3 py-3 text-right text-amber-700">{row.late ?? 0}</td>
                          <td className="px-5 py-3 text-right text-slate-600">{(row.absent ?? 0) + (row.excused ?? 0)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>

            <Card>
              <CardHeader><CardTitle className="text-sm">Ringkasan status</CardTitle></CardHeader>
              <CardBody className="space-y-3">
                {STATUSES.map((status) => (
                  <div key={status} className="flex items-center justify-between gap-3">
                    <Badge tone={statusTone(status)}>{displayStatus(status)}</Badge>
                    <span className="text-sm font-semibold tabular-nums text-slate-800">{Number(summary?.[status.toLowerCase()] ?? 0)}</span>
                  </div>
                ))}
                <p className="border-t border-slate-100 pt-3 text-xs leading-5 text-slate-500">{dashboardQuery.data?.note ?? 'Angka bersifat deskriptif dan mengikuti cakupan akses Anda.'}</p>
              </CardBody>
            </Card>
          </div>
        </>
      )}

      <Modal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title="Catat presensi shalat"
        size="lg"
        footer={
          <>
            <Button variant="outline" onClick={() => setFormOpen(false)}>Batal</Button>
            <Button type="submit" form="religious-attendance-form" loading={recordMutation.isPending}>Simpan presensi</Button>
          </>
        }
      >
        <form id="religious-attendance-form" onSubmit={submitAttendance} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Jenis shalat">
            <Select value={form.prayer_type} onChange={(event) => updateForm({ prayer_type: event.target.value })} required>
              {prayerTypes.map((prayer) => <option key={prayer.code} value={prayer.code}>{prayer.name}</option>)}
            </Select>
          </FormField>
          <FormField label="Tanggal">
            <Input type="date" value={date} onChange={(event) => { setDate(event.target.value); updateForm({ recorded_at: `${event.target.value}T${form.recorded_at.slice(11)}` }); }} required />
          </FormField>
          <FormField label="Jenis peserta">
            <Select value={form.participant_type} onChange={(event) => updateForm({ participant_type: event.target.value as AttendanceFormState['participant_type'], student_id: '', employee_id: '' })}>
              <option value="STUDENT">Siswa</option>
              <option value="EMPLOYEE">Pegawai</option>
            </Select>
          </FormField>
          {form.participant_type === 'STUDENT' ? (
            <FormField label="ID siswa"><Input type="number" min="1" value={form.student_id} onChange={(event) => updateForm({ student_id: event.target.value })} placeholder="Masukkan ID siswa" required /></FormField>
          ) : (
            <FormField label="ID pegawai"><Input type="number" min="1" value={form.employee_id} onChange={(event) => updateForm({ employee_id: event.target.value })} placeholder="Masukkan ID pegawai" required /></FormField>
          )}
          {can('religious.mosque.view') ? (
            <FormField label="Masjid">
              <Select value={form.mosque_id} onChange={(event) => updateForm({ mosque_id: event.target.value })}>
                <option value="">Tidak ditentukan</option>
                {(mosqueQuery.data ?? []).map((mosque) => <option key={mosque.id} value={mosque.id}>{mosque.name}</option>)}
              </Select>
            </FormField>
          ) : (
            <FormField label="ID masjid (opsional)"><Input type="number" min="1" value={form.mosque_id} onChange={(event) => updateForm({ mosque_id: event.target.value })} placeholder="ID masjid" /></FormField>
          )}
          <FormField label="ID kampus (opsional)"><Input type="number" min="1" value={form.campus_id} onChange={(event) => updateForm({ campus_id: event.target.value })} placeholder="ID kampus" /></FormField>
          <FormField label="ID jadwal (opsional)" hint="Isi jika ingin mengikat presensi ke jadwal tertentu."><Input type="number" min="1" value={form.prayer_schedule_id} onChange={(event) => updateForm({ prayer_schedule_id: event.target.value })} placeholder="ID jadwal" /></FormField>
          <FormField label="Waktu pencatatan">
            <Input type="datetime-local" value={form.recorded_at} onChange={(event) => updateForm({ recorded_at: event.target.value })} />
          </FormField>
          <FormField label="Status (opsional)" hint="Kosongkan agar sistem menghitung status dari jendela presensi.">
            <Select value={form.status} onChange={(event) => updateForm({ status: event.target.value })}>
              <option value="">Otomatis</option>
              {STATUSES.map((status) => <option key={status} value={status}>{displayStatus(status)}</option>)}
            </Select>
          </FormField>
          <FormField label="Catatan" className="sm:col-span-2"><Textarea value={form.notes} onChange={(event) => updateForm({ notes: event.target.value })} placeholder="Catatan operasional (opsional)" /></FormField>

          <div className="sm:col-span-2">
            <div className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <p className="text-sm font-medium text-slate-800">Pemeriksaan jendela presensi</p>
                <p className="mt-1 text-xs leading-5 text-slate-500">Periksa waktu dan jadwal terlebih dahulu. Server tetap memvalidasi ulang saat disimpan.</p>
              </div>
              <Button type="button" variant="outline" size="sm" onClick={checkWindow} loading={windowMutation.isPending}>
                <Clock3 className="h-4 w-4" /> Cek jendela
              </Button>
            </div>
            {windowResult && (
              <div className={`mt-3 rounded-lg border p-3 text-sm ${windowResult.allowed ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}>
                <div className="flex items-start gap-2">
                  <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" />
                  <div>
                    <p className="font-semibold">{windowResult.allowed ? `Dapat dicatat${windowResult.status_hint ? ` · ${displayStatus(windowResult.status_hint)}` : ''}` : 'Di luar jendela presensi'}</p>
                    {windowResult.reason && <p className="mt-1 text-xs leading-5">{windowResult.reason}</p>}
                  </div>
                </div>
              </div>
            )}
          </div>

          {canApprove && windowResult && !windowResult.allowed && windowResult.overrideable && (
            <div className="sm:col-span-2 space-y-3 rounded-xl border border-amber-200 bg-amber-50/70 p-3">
              <label className="flex items-start gap-2 text-sm font-medium text-amber-950">
                <input type="checkbox" checked={form.authorized} onChange={(event) => updateForm({ authorized: event.target.checked })} className="mt-0.5 rounded border-amber-400 text-emerald-700 focus:ring-emerald-600" />
                Saya menyetujui override di luar jendela
              </label>
              {form.authorized && (
                <FormField label="Alasan override (wajib)">
                  <Textarea value={form.authorization_reason} onChange={(event) => updateForm({ authorization_reason: event.target.value })} minLength={5} maxLength={1000} required placeholder="Jelaskan alasan dan konteks persetujuan (minimal 5 karakter)" />
                </FormField>
              )}
            </div>
          )}
        </form>
      </Modal>
    </div>
  );
}
