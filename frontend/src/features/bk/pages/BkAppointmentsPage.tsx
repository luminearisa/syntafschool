import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { bkApi } from '../api/bkApi';

/**
 * Janji temu konseling.
 *
 * Sebelum menyimpan, layar meminta pratinjau bentrok ke server (jadwal pelajaran,
 * ketersediaan konselor, janji lain, kegiatan asrama, ujian/kegiatan sekolah).
 * Bila ada bentrok, janji tidak dibuat kecuali pemakainya koordinator dan
 * sengaja memaksa — dan pemaksaan itu tercatat.
 */
export default function BkAppointmentsPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();

  const [form, setForm] = useState({ student_id: '', counselor_id: '', date: '', start: '09:00', end: '09:45', mode: 'IN_PERSON', purpose: '' });
  const [conflicts, setConflicts] = useState<{ type: string; label: string; detail?: string | null }[]>([]);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const appointments = useQuery({
    queryKey: ['bk', 'appointments'],
    queryFn: () => bkApi.appointments({ upcoming: 1, per_page: 25 }).then((r) => r.data.data),
  });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ['bk', 'appointments'] });
  const payload = () => ({
    student_id: Number(form.student_id),
    counselor_id: form.counselor_id === '' ? undefined : Number(form.counselor_id),
    scheduled_start: `${form.date}T${form.start}:00`,
    scheduled_end: `${form.date}T${form.end}:00`,
    mode: form.mode,
    purpose: form.purpose || undefined,
  });

  const checkConflicts = useMutation({
    mutationFn: () => bkApi.checkConflicts(payload()).then((r) => r.data.data),
    onSuccess: (data) => {
      setConflicts(data);
      setNotice(data.length === 0
        ? { tone: 'success', text: 'Tidak ada bentrok jadwal yang terdeteksi.' }
        : { tone: 'danger', text: 'Ada bentrok jadwal — periksa daftar di bawah sebelum menyimpan.' });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const create = useMutation({
    mutationFn: (force: boolean) => bkApi.createAppointment({ ...payload(), force }).then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Janji temu dibuat dan pengingat akan dikirim.' });
      setConflicts([]);
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const cancel = useMutation({
    mutationFn: (id: number) => bkApi.cancelAppointment(id, 'Dibatalkan dari layar jadwal.').then((r) => r.data.data),
    onSuccess: () => { setNotice({ tone: 'success', text: 'Janji temu dibatalkan.' }); invalidate(); },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const rows = appointments.data ?? [];

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">Janji Temu Konseling</h1>
        <p className="mt-1 text-sm text-slate-500">Penjadwalan yang sadar bentrok: jadwal pelajaran, ketersediaan konselor, asrama, dan kegiatan sekolah.</p>
      </div>

      {notice && (
        <div role="status" className={notice.tone === 'success'
          ? 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800'
          : 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800'}>
          {notice.text}
        </div>
      )}

      {can('bk.appointment.manage') && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Buat janji temu</CardTitle></CardHeader>
          <CardBody className="grid gap-3 md:grid-cols-4">
            <Input placeholder="ID siswa" value={form.student_id} onChange={(event) => setForm({ ...form, student_id: event.target.value })} />
            <Input placeholder="ID konselor (opsional)" value={form.counselor_id} onChange={(event) => setForm({ ...form, counselor_id: event.target.value })} />
            <Input type="date" value={form.date} onChange={(event) => setForm({ ...form, date: event.target.value })} aria-label="Tanggal" />
            <div className="flex gap-2">
              <Input type="time" value={form.start} onChange={(event) => setForm({ ...form, start: event.target.value })} aria-label="Mulai" />
              <Input type="time" value={form.end} onChange={(event) => setForm({ ...form, end: event.target.value })} aria-label="Selesai" />
            </div>
            <Select value={form.mode} onChange={(event) => setForm({ ...form, mode: event.target.value })} aria-label="Moda">
              {['IN_PERSON', 'ONLINE', 'PHONE', 'OTHER'].map((mode) => <option key={mode} value={mode}>{mode}</option>)}
            </Select>
            <div className="md:col-span-2">
              <Input placeholder="Keperluan ringkas (tanpa detail konseling)" value={form.purpose} onChange={(event) => setForm({ ...form, purpose: event.target.value })} />
            </div>
            <Button variant="outline" loading={checkConflicts.isPending} disabled={form.student_id === '' || form.date === ''} onClick={() => checkConflicts.mutate()}>
              Periksa bentrok
            </Button>

            {conflicts.length > 0 && (
              <div className="md:col-span-4 space-y-1 rounded-md bg-amber-50 p-3 text-xs text-amber-900">
                <p className="font-medium">Bentrok terdeteksi:</p>
                <ul className="list-inside list-disc">
                  {conflicts.map((conflict, index) => (
                    <li key={index}>{conflict.label}{conflict.detail ? ` — ${conflict.detail}` : ''}</li>
                  ))}
                </ul>
                <div className="flex gap-2 pt-1">
                  <Button size="sm" variant="ghost" loading={create.isPending} onClick={() => create.mutate(true)}>Simpan dengan paksa</Button>
                  <span className="self-center">Hanya koordinator yang dapat memaksa.</span>
                </div>
              </div>
            )}

            <div className="md:col-span-4">
              <Button loading={create.isPending} disabled={form.student_id === '' || form.date === ''} onClick={() => create.mutate(false)}>
                Simpan janji temu
              </Button>
            </div>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader><CardTitle className="text-sm">Janji temu mendatang</CardTitle></CardHeader>
        <CardBody className="space-y-2 text-sm">
          {appointments.isLoading ? <Spinner /> : rows.length === 0 ? (
            <EmptyState title="Belum ada janji" description="Jadwal konseling mendatang akan tampil di sini." />
          ) : rows.map((row) => (
            <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
              <div>
                <p className="text-slate-800">
                  {row.scheduled_start?.slice(0, 16).replace('T', ' ')} · {row.student?.full_name ?? ''}
                </p>
                <p className="text-xs text-slate-500">
                  {row.appointment_number} · {row.mode} · {row.counselor?.name ?? 'konselor belum ditetapkan'}
                  {row.conflict_state === 'OVERRIDDEN' ? ' · dijadwalkan dengan paksa' : ''}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge tone={row.status === 'COMPLETED' ? 'success' : row.status === 'CANCELLED' ? 'neutral' : 'warning'}>{row.status}</Badge>
                {can('bk.appointment.manage') && ['SCHEDULED', 'CONFIRMED', 'RESCHEDULED'].includes(row.status) && (
                  <>
                    <Button size="sm" variant="ghost" onClick={() => bkApi.appointmentAttendance(row.id, 'PRESENT')}>Hadir</Button>
                    <Button size="sm" variant="ghost" onClick={() => bkApi.appointmentAttendance(row.id, 'ABSENT', 'Tidak hadir tanpa kabar')}>Tidak hadir</Button>
                    <Button size="sm" variant="ghost" loading={cancel.isPending} onClick={() => cancel.mutate(row.id)}>Batalkan</Button>
                  </>
                )}
              </div>
            </div>
          ))}
        </CardBody>
      </Card>
    </div>
  );
}
