import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Clock3, History, Plus, RefreshCw, WandSparkles } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { getErrorMessage } from '@/lib/axios';
import { usePermission } from '@/hooks/usePermission';
import { religiousApi } from '../api/religiousApi';
import type { PrayerScheduleRow } from '@/types/religious';
import {
  ErrorCard,
  FormField,
  localDate,
  LoadingCard,
  prayerLabel,
  ReligiousPageHeader,
  statusTone,
} from '../components/ReligiousUi';

interface ScheduleFormState {
  prayer_type: string;
  adhan_time: string;
  iqamah_time: string;
  start_time: string;
  end_time: string;
  notes: string;
}

const EMPTY_FORM: ScheduleFormState = {
  prayer_type: 'FAJR',
  adhan_time: '',
  iqamah_time: '',
  start_time: '',
  end_time: '',
  notes: '',
};

export function ReligiousPrayersPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canManage = can('religious.prayer.schedule.manage');
  const [date, setDate] = useState(localDate());
  const [mosqueId, setMosqueId] = useState<number | ''>('');
  const [campusId, setCampusId] = useState<number | ''>('');
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<PrayerScheduleRow | null>(null);
  const [historyId, setHistoryId] = useState<number | null>(null);
  const [form, setForm] = useState<ScheduleFormState>(EMPTY_FORM);

  const typeQuery = useQuery({
    queryKey: ['religious', 'prayer-types'],
    queryFn: religiousApi.prayerTypes,
  });
  const mosqueQuery = useQuery({
    queryKey: ['religious', 'mosques', 'prayer-options'],
    queryFn: () => religiousApi.listMosques({}),
    enabled: can('religious.mosque.view'),
  });
  const scheduleQuery = useQuery({
    queryKey: ['religious', 'prayer-schedule', date, mosqueId, campusId],
    queryFn: () => religiousApi.prayerSchedule({ date, mosque_id: mosqueId, campus_id: campusId }),
  });
  const sourcesQuery = useQuery({
    queryKey: ['religious', 'prayer-sources'],
    queryFn: religiousApi.prayerSources,
  });
  const historyQuery = useQuery({
    queryKey: ['religious', 'schedule-versions', historyId],
    queryFn: () => religiousApi.prayerScheduleVersions(historyId!),
    enabled: historyId !== null,
  });

  const types = useMemo(
    () => typeQuery.data?.length ? typeQuery.data : [
      { code: 'FAJR', name: 'Subuh' },
      { code: 'DHUHR', name: 'Dzuhur' },
      { code: 'ASR', name: 'Ashar' },
      { code: 'MAGHRIB', name: 'Maghrib' },
      { code: 'ISHA', name: 'Isya' },
    ],
    [typeQuery.data],
  );
  const names = Object.fromEntries(types.map((item) => [item.code, item.name]));

  const saveMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => religiousApi.savePrayerSchedule(payload),
    onSuccess: () => {
      toast.success(editing ? 'Jadwal shalat diperbarui' : 'Jadwal shalat disimpan');
      setModalOpen(false);
      setEditing(null);
      queryClient.invalidateQueries({ queryKey: ['religious', 'prayer-schedule'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const generateMutation = useMutation({
    mutationFn: () => religiousApi.generatePrayerSchedule({
      date,
      mosque_id: mosqueId || undefined,
      campus_id: campusId || undefined,
    }),
    onSuccess: (result) => {
      if (result.created > 0) {
        toast.success(`${result.created} jadwal berhasil dibuat dari ${result.source ?? 'penyedia waktu'}`);
      } else {
        toast.warning(result.reason ?? 'Penyedia belum menghasilkan jadwal untuk lokasi ini.');
      }
      queryClient.invalidateQueries({ queryKey: ['religious', 'prayer-schedule'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const startCreate = (row?: PrayerScheduleRow) => {
    setEditing(row ?? null);
    setForm(row ? {
      prayer_type: row.prayer_type,
      adhan_time: row.adhan_time ?? '',
      iqamah_time: row.iqamah_time ?? '',
      start_time: row.start_time ?? '',
      end_time: row.end_time ?? '',
      notes: '',
    } : { ...EMPTY_FORM, prayer_type: types[0]?.code ?? 'FAJR' });
    setModalOpen(true);
  };

  const submitSchedule = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const payload: Record<string, unknown> = {
      schedule_date: date,
      mosque_id: mosqueId || undefined,
      campus_id: campusId || undefined,
      prayer_type: form.prayer_type,
      adhan_time: form.adhan_time || undefined,
      iqamah_time: form.iqamah_time || undefined,
      start_time: form.start_time || undefined,
      end_time: form.end_time || undefined,
      notes: form.notes || undefined,
    };
    saveMutation.mutate(payload);
  };

  const schedules = scheduleQuery.data?.prayers ?? [];
  const provider = sourcesQuery.data?.provider ?? {};
  const hasLocation = Boolean(mosqueId || campusId);

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Jadwal shalat"
        description="Kelola waktu adzan, iqamah, mulai dan selesai. Perubahan jadwal disimpan sebagai versi agar riwayatnya dapat diaudit."
        actions={
          <>
            <Button
              variant="outline"
              size="icon"
              aria-label="Muat ulang jadwal"
              title="Muat ulang jadwal"
              onClick={() => scheduleQuery.refetch()}
            >
              <RefreshCw className={scheduleQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            {canManage && (
              <>
                <Button
                  variant="outline"
                  onClick={() => generateMutation.mutate()}
                  loading={generateMutation.isPending}
                  disabled={!hasLocation}
                  title={!hasLocation ? 'Pilih masjid atau isi ID kampus terlebih dahulu' : undefined}
                >
                  <WandSparkles className="h-4 w-4" /> Buat dari sumber waktu
                </Button>
                <Button onClick={() => startCreate()} disabled={!hasLocation} title={!hasLocation ? 'Pilih masjid atau isi ID kampus terlebih dahulu' : undefined}>
                  <Plus className="h-4 w-4" /> Atur jadwal
                </Button>
              </>
            )}
          </>
        }
      />

      <Card>
        <CardBody className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end">
          <FormField label="Tanggal">
            <Input type="date" value={date} onChange={(event) => setDate(event.target.value)} />
          </FormField>
          {can('religious.mosque.view') ? (
            <FormField label="Masjid">
              <Select value={mosqueId} onChange={(event) => setMosqueId(event.target.value ? Number(event.target.value) : '')}>
                <option value="">Semua masjid</option>
                {(mosqueQuery.data ?? []).map((mosque) => (
                  <option key={mosque.id} value={mosque.id}>{mosque.name}{mosque.code ? ` · ${mosque.code}` : ''}</option>
                ))}
              </Select>
            </FormField>
          ) : (
            <FormField label="ID masjid (opsional)" hint="Masukkan ID jika Anda mengetahui lokasi jadwal.">
              <Input type="number" min="1" value={mosqueId} onChange={(event) => setMosqueId(event.target.value ? Number(event.target.value) : '')} placeholder="ID masjid" />
            </FormField>
          )}
          <FormField label="ID kampus (opsional)">
            <Input type="number" min="1" value={campusId} onChange={(event) => setCampusId(event.target.value ? Number(event.target.value) : '')} placeholder="ID kampus" />
          </FormField>
          <div className="flex h-9 items-center gap-2 text-xs text-slate-500">
            <Badge tone={provider.available ? 'success' : 'warning'}>{provider.available ? 'Sumber tersedia' : 'Mode manual/cadangan'}</Badge>
          </div>
        </CardBody>
      </Card>

      {scheduleQuery.isLoading ? (
        <LoadingCard label="Memuat jadwal shalat..." />
      ) : scheduleQuery.isError ? (
        <ErrorCard message={getErrorMessage(scheduleQuery.error)} onRetry={() => scheduleQuery.refetch()} />
      ) : (
        <Card>
          <CardHeader className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <CardTitle className="text-sm">Jadwal tanggal {date}</CardTitle>
              <p className="mt-1 text-xs text-slate-500">
                {scheduleQuery.data?.source ? `Sumber aktif: ${scheduleQuery.data.source}` : 'Sumber jadwal mengikuti konfigurasi institusi'}
              </p>
            </div>
            {scheduleQuery.data?.next && (
              <Badge tone="info">Berikutnya: {prayerLabel(scheduleQuery.data.next.prayer_type, names)} {scheduleQuery.data.next.adhan_time ?? ''}</Badge>
            )}
          </CardHeader>
          {schedules.length === 0 ? (
            <p className="px-5 pb-5 text-sm text-slate-500">Belum ada jadwal di tanggal dan lokasi ini. Pilih masjid/kampus lalu buat jadwal dari sumber waktu atau isi secara manual.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm">
                <thead>
                  <tr className="border-y border-slate-100 bg-slate-50/70 text-left text-xs uppercase tracking-wide text-slate-400">
                    <th className="px-5 py-3 font-medium">Shalat</th>
                    <th className="px-3 py-3 font-medium">Adzan</th>
                    <th className="px-3 py-3 font-medium">Iqamah</th>
                    <th className="px-3 py-3 font-medium">Waktu shalat</th>
                    <th className="px-3 py-3 font-medium">Sumber / versi</th>
                    <th className="px-3 py-3 font-medium">Status</th>
                    <th className="px-5 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {schedules.map((row) => (
                    <tr key={`${row.prayer_type}-${row.schedule_id ?? row.version ?? 0}`} className="border-b border-slate-50 last:border-0">
                      <td className="px-5 py-3 font-medium text-slate-800">{prayerLabel(row.prayer_type, names)}</td>
                      <td className="px-3 py-3 text-slate-700">{row.adhan_time ?? '—'}</td>
                      <td className="px-3 py-3 text-slate-700">{row.iqamah_time ?? '—'}</td>
                      <td className="px-3 py-3 text-slate-600">{row.start_time ?? '—'}{row.end_time ? ` – ${row.end_time}` : ''}</td>
                      <td className="px-3 py-3 text-xs text-slate-500">{row.source ?? '—'}{row.version ? ` · v${row.version}` : ''}</td>
                      <td className="px-3 py-3"><Badge tone={statusTone(row.status)}>{row.status ?? '—'}</Badge></td>
                      <td className="px-5 py-3">
                        <div className="flex justify-end gap-1">
                          {row.schedule_id && (
                            <Button size="icon" variant="ghost" title="Riwayat versi" aria-label={`Riwayat jadwal ${prayerLabel(row.prayer_type, names)}`} onClick={() => setHistoryId(row.schedule_id!)}>
                              <History className="h-4 w-4" />
                            </Button>
                          )}
                          {canManage && <Button size="sm" variant="outline" onClick={() => startCreate(row)}>Ubah</Button>}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? `Ubah jadwal ${prayerLabel(editing.prayer_type, names)}` : 'Atur jadwal shalat'}
        footer={
          <>
            <Button variant="outline" onClick={() => setModalOpen(false)}>Batal</Button>
            <Button type="submit" form="religious-prayer-form" loading={saveMutation.isPending} disabled={!hasLocation}>Simpan jadwal</Button>
          </>
        }
      >
        <form id="religious-prayer-form" onSubmit={submitSchedule} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Jenis shalat">
            <Select value={form.prayer_type} onChange={(event) => setForm({ ...form, prayer_type: event.target.value })} required>
              {types.map((type) => <option key={type.code} value={type.code}>{type.name} ({type.code})</option>)}
            </Select>
          </FormField>
          <FormField label="Tanggal">
            <Input type="date" value={date} onChange={(event) => setDate(event.target.value)} required />
          </FormField>
          <FormField label="Waktu adzan"><Input type="time" value={form.adhan_time} onChange={(event) => setForm({ ...form, adhan_time: event.target.value })} /></FormField>
          <FormField label="Waktu iqamah"><Input type="time" value={form.iqamah_time} onChange={(event) => setForm({ ...form, iqamah_time: event.target.value })} /></FormField>
          <FormField label="Mulai"><Input type="time" value={form.start_time} onChange={(event) => setForm({ ...form, start_time: event.target.value })} /></FormField>
          <FormField label="Selesai"><Input type="time" value={form.end_time} onChange={(event) => setForm({ ...form, end_time: event.target.value })} /></FormField>
          <FormField label="Catatan" className="sm:col-span-2"><Input value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} placeholder="Opsional" /></FormField>
        </form>
        {!hasLocation && (
          <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">
            Jadwal ini belum terikat pada masjid atau kampus. Tutup formulir lalu pilih lokasi sebelum menyimpan.
          </p>
        )}
      </Modal>

      <Modal open={historyId !== null} onClose={() => setHistoryId(null)} title="Riwayat perubahan jadwal" size="lg">
        {historyQuery.isLoading ? (
          <div className="flex items-center gap-2 py-8 text-sm text-slate-500"><Clock3 className="h-4 w-4" /> Memuat riwayat...</div>
        ) : historyQuery.isError ? (
          <p className="text-sm text-rose-700">{getErrorMessage(historyQuery.error)}</p>
        ) : (
          <div className="space-y-3">
            <p className="text-sm font-medium text-slate-700">
              {String(historyQuery.data?.schedule.prayer_type ?? '')} · {String(historyQuery.data?.schedule.schedule_date ?? '')}
            </p>
            {(historyQuery.data?.versions ?? []).length === 0 ? (
              <p className="text-sm text-slate-500">Belum ada riwayat versi.</p>
            ) : (
              <ol className="space-y-3">
                {historyQuery.data?.versions.map((version, index) => (
                  <li key={`${String(version.version_number)}-${index}`} className="rounded-lg border border-slate-200 p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <Badge tone="info">Versi {String(version.version_number ?? '—')}</Badge>
                      <span className="text-xs text-slate-500">{String(version.created_at ?? '')}</span>
                    </div>
                    <p className="mt-2 text-sm text-slate-700">{String(version.change_reason ?? 'Perubahan jadwal')}</p>
                  </li>
                ))}
              </ol>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
}
