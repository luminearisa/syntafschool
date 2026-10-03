import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookOpenText, Check, Plus, RefreshCw, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';
import type { ReligiousKhutbah } from '@/types/religious';
import { religiousApi } from '../api/religiousApi';
import { displayStatus, ErrorCard, FormField, localDate, LoadingCard, ReligiousPageHeader, statusTone } from '../components/ReligiousUi';

const KHUTBAH_CATEGORIES = ['AKHLAK', 'AQIDAH', 'FIQH', 'EDUCATION', 'FAMILY', 'RAMADAN', 'YOUTH', 'LEADERSHIP', 'SOCIAL', 'CHARACTER'];

function nextFriday(): string {
  const date = new Date();
  const daysUntilFriday = (5 - date.getDay() + 7) % 7;
  date.setDate(date.getDate() + daysUntilFriday);
  return localDate(date);
}

interface FridayForm {
  mosque_id: string;
  prayer_date: string;
  topic: string;
  theme: string;
  start_time: string;
  end_time: string;
  estimated_attendance: string;
}
const blankFriday = (): FridayForm => ({ mosque_id: '', prayer_date: nextFriday(), topic: '', theme: '', start_time: '12:00', end_time: '13:00', estimated_attendance: '' });

interface KhutbahForm {
  title: string;
  theme: string;
  category_code: string;
  khatib_name: string;
  prayer_date: string;
  language: string;
  duration_minutes: string;
  summary: string;
  content: string;
}
const blankKhutbah = (): KhutbahForm => ({ title: '', theme: '', category_code: 'AKHLAK', khatib_name: '', prayer_date: '', language: 'id', duration_minutes: '', summary: '', content: '' });

export function ReligiousFridayPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [mosqueId, setMosqueId] = useState<number | ''>('');
  const [fridayFormOpen, setFridayFormOpen] = useState(false);
  const [fridayForm, setFridayForm] = useState<FridayForm>(blankFriday);
  const [khutbahFormOpen, setKhutbahFormOpen] = useState(false);
  const [khutbahForm, setKhutbahForm] = useState<KhutbahForm>(blankKhutbah);
  const [attendanceId, setAttendanceId] = useState<number | null>(null);
  const [actualAttendance, setActualAttendance] = useState('');

  const fridayQuery = useQuery({
    queryKey: ['religious', 'friday', mosqueId],
    queryFn: () => religiousApi.friday({ mosque_id: mosqueId }),
  });
  const khutbahQuery = useQuery({
    queryKey: ['religious', 'khutbahs'],
    queryFn: () => religiousApi.khutbahs(),
    enabled: can('religious.khutbah.view'),
  });
  const mosqueQuery = useQuery({
    queryKey: ['religious', 'mosques', 'friday-options'],
    queryFn: () => religiousApi.listMosques({}),
    enabled: can('religious.mosque.view'),
  });
  const categoryQuery = useQuery({
    queryKey: ['religious', 'khutbah-categories'],
    queryFn: religiousApi.khutbahCategories,
    enabled: can('religious.khutbah.view'),
  });
  const khutbahCategories = categoryQuery.data?.length
    ? categoryQuery.data.map((category) => ({ code: category.code, label: category.name }))
    : KHUTBAH_CATEGORIES.map((code) => ({ code, label: code.replace(/_/g, ' ') }));

  const scheduleMutation = useMutation({
    mutationFn: () => religiousApi.scheduleFriday({
      mosque_id: Number(fridayForm.mosque_id),
      prayer_date: fridayForm.prayer_date,
      topic: fridayForm.topic.trim() || undefined,
      theme: fridayForm.theme.trim() || undefined,
      start_time: fridayForm.start_time || undefined,
      end_time: fridayForm.end_time || undefined,
      estimated_attendance: fridayForm.estimated_attendance ? Number(fridayForm.estimated_attendance) : undefined,
    }),
    onSuccess: () => {
      toast.success('Jadwal Jumat berhasil disimpan');
      setFridayFormOpen(false);
      setFridayForm(blankFriday());
      queryClient.invalidateQueries({ queryKey: ['religious', 'friday'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const attendanceMutation = useMutation({
    mutationFn: () => religiousApi.recordFridayAttendance(attendanceId!, Number(actualAttendance)),
    onSuccess: () => {
      toast.success('Kehadiran jamaah Jumat dicatat');
      setAttendanceId(null);
      setActualAttendance('');
      queryClient.invalidateQueries({ queryKey: ['religious', 'friday'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const createKhutbahMutation = useMutation({
    mutationFn: () => religiousApi.createKhutbah({
      title: khutbahForm.title.trim(),
      theme: khutbahForm.theme.trim() || undefined,
      category_code: khutbahForm.category_code,
      khatib_name: khutbahForm.khatib_name.trim() || undefined,
      prayer_date: khutbahForm.prayer_date || undefined,
      language: khutbahForm.language,
      duration_minutes: khutbahForm.duration_minutes ? Number(khutbahForm.duration_minutes) : undefined,
      summary: khutbahForm.summary.trim() || undefined,
      content: khutbahForm.content.trim() || undefined,
    }),
    onSuccess: () => {
      toast.success('Draf khutbah dibuat');
      setKhutbahFormOpen(false);
      setKhutbahForm(blankKhutbah());
      queryClient.invalidateQueries({ queryKey: ['religious', 'khutbahs'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const transitionMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => religiousApi.transitionKhutbah(id, status),
    onSuccess: () => {
      toast.success('Status khutbah diperbarui');
      queryClient.invalidateQueries({ queryKey: ['religious', 'khutbahs'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const submitFriday = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!fridayForm.mosque_id) {
      toast.error('Masjid wajib dipilih.');
      return;
    }
    const weekday = new Date(`${fridayForm.prayer_date}T12:00:00`).getDay();
    if (weekday !== 5) {
      toast.error('Tanggal shalat harus hari Jumat.');
      return;
    }
    scheduleMutation.mutate();
  };
  const submitAttendance = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!actualAttendance || Number(actualAttendance) < 0) {
      toast.error('Jumlah kehadiran aktual wajib diisi.');
      return;
    }
    attendanceMutation.mutate();
  };
  const submitKhutbah = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!khutbahForm.title.trim()) {
      toast.error('Judul khutbah wajib diisi.');
      return;
    }
    createKhutbahMutation.mutate();
  };

  const history = fridayQuery.data?.history ?? [];
  const upcoming = fridayQuery.data?.upcoming ?? [];
  const khutbahs: ReligiousKhutbah[] = khutbahQuery.data ?? [];

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Jumat & khutbah"
        description="Atur jadwal shalat Jumat, catat jumlah jamaah aktual, dan kelola pustaka khutbah melalui alur draf, review, persetujuan, dan penyampaian."
        actions={
          <>
            <Button variant="outline" size="icon" aria-label="Muat ulang Jumat dan khutbah" title="Muat ulang" onClick={() => { fridayQuery.refetch(); if (can('religious.khutbah.view')) khutbahQuery.refetch(); }}><RefreshCw className={fridayQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} /></Button>
            <Can perm="religious.friday.manage"><Button onClick={() => { setFridayForm(blankFriday()); setFridayFormOpen(true); }}><Plus className="h-4 w-4" /> Jadwalkan Jumat</Button></Can>
            <Can perm="religious.khutbah.create"><Button variant="outline" onClick={() => { setKhutbahForm(blankKhutbah()); setKhutbahFormOpen(true); }}><BookOpenText className="h-4 w-4" /> Draf khutbah</Button></Can>
          </>
        }
      />

      <Card>
        <CardBody className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
          <FormField label="Masjid">
            {can('religious.mosque.view') ? (
              <Select value={mosqueId} onChange={(event) => setMosqueId(event.target.value ? Number(event.target.value) : '')}>
                <option value="">Semua masjid</option>
                {(mosqueQuery.data ?? []).map((mosque) => <option key={mosque.id} value={mosque.id}>{mosque.name}</option>)}
              </Select>
            ) : <Input type="number" min="1" value={mosqueId} onChange={(event) => setMosqueId(event.target.value ? Number(event.target.value) : '')} placeholder="ID masjid (opsional)" />}
          </FormField>
          <div className="flex items-end text-xs text-slate-500">Data jadwal ditampilkan sesuai cakupan izin pengguna.</div>
        </CardBody>
      </Card>

      {fridayQuery.isLoading ? <LoadingCard label="Memuat jadwal Jumat..." /> : fridayQuery.isError ? <ErrorCard message={getErrorMessage(fridayQuery.error)} onRetry={() => fridayQuery.refetch()} /> : (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          <Card>
            <CardHeader><CardTitle className="text-sm">Jumat mendatang</CardTitle></CardHeader>
            <CardBody className="space-y-2">
              {upcoming.length === 0 ? <p className="text-sm text-slate-500">Belum ada jadwal Jumat mendatang.</p> : upcoming.map((item, index) => (
                <div key={`${String(item.id ?? index)}`} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-100 p-3">
                  <div><p className="text-sm font-medium text-slate-800">{String(item.topic ?? 'Shalat Jumat')}</p><p className="mt-1 text-xs text-slate-500">{String(item.date ?? '—')} · {String(item.start_time ?? '—')} · Masjid #{String(item.mosque_id ?? mosqueId ?? '—')}</p></div>
                  <Badge tone={statusTone(String(item.status ?? 'SCHEDULED'))}>{displayStatus(String(item.status ?? 'SCHEDULED'))}</Badge>
                </div>
              ))}
            </CardBody>
          </Card>
          <Card>
            <CardHeader><CardTitle className="text-sm">Riwayat Jumat</CardTitle></CardHeader>
            <CardBody className="space-y-2">
              {history.length === 0 ? <p className="text-sm text-slate-500">Riwayat belum tersedia.</p> : history.slice(0, 8).map((item) => (
                <div key={item.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-100 p-3">
                  <div><p className="text-sm font-medium text-slate-800">{item.topic ?? item.theme ?? 'Shalat Jumat'}</p><p className="mt-1 text-xs text-slate-500">{item.date} · {item.start_time ?? '—'} · Khatib: {item.khatib ?? '—'} · Masjid #{item.mosque_id}</p><p className="mt-1 text-xs text-slate-500">Hadir aktual {item.actual_attendance ?? '—'}{item.estimated_attendance != null ? ` · Perkiraan ${item.estimated_attendance}` : ''}</p></div>
                  <div className="flex items-center gap-2"><Badge tone={statusTone(item.status)}>{displayStatus(item.status)}</Badge>{item.status !== 'COMPLETED' && <Can perm="religious.friday.manage"><Button size="sm" variant="outline" onClick={() => { setAttendanceId(item.id); setActualAttendance(item.actual_attendance == null ? '' : String(item.actual_attendance)); }}><Users className="h-4 w-4" /> Catat jamaah</Button></Can>}</div>
                </div>
              ))}
            </CardBody>
          </Card>
        </div>
      )}

      {can('religious.khutbah.view') && (
        <Card>
          <CardHeader className="flex flex-wrap items-center justify-between gap-2"><div><CardTitle className="text-sm">Pustaka khutbah</CardTitle><p className="mt-1 text-xs text-slate-500">Materi disimpan sebagai draf dan berpindah status melalui alur persetujuan.</p></div><Badge tone="info">{khutbahs.length} materi</Badge></CardHeader>
          {khutbahQuery.isLoading ? <CardBody><p className="text-sm text-slate-500">Memuat pustaka khutbah...</p></CardBody> : khutbahQuery.isError ? <CardBody><p className="text-sm text-rose-700">{getErrorMessage(khutbahQuery.error)}</p></CardBody> : khutbahs.length === 0 ? <CardBody><EmptyState title="Belum ada materi khutbah" description="Draf khutbah dapat dibuat oleh pengguna dengan izin yang sesuai." /></CardBody> : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm">
                <thead><tr className="border-y border-slate-100 bg-slate-50/70 text-left text-xs uppercase tracking-wide text-slate-400"><th className="px-5 py-3 font-medium">Materi</th><th className="px-3 py-3 font-medium">Kategori / khatib</th><th className="px-3 py-3 font-medium">Tanggal</th><th className="px-3 py-3 font-medium">Status</th><th className="px-5 py-3 text-right font-medium">Alur</th></tr></thead>
                <tbody>{khutbahs.map((item) => <tr key={item.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-3"><p className="font-medium text-slate-800">{item.title}</p><p className="mt-0.5 text-xs text-slate-500">{item.code ?? `ID ${item.id}`} · v{item.version ?? 1}{item.has_document ? ' · ada dokumen' : ''}</p></td>
                  <td className="px-3 py-3 text-xs text-slate-600">{item.category_code ?? '—'}<span className="block mt-1">{item.khatib_name ?? '—'}</span></td>
                  <td className="px-3 py-3 text-slate-600">{item.prayer_date ?? '—'}</td>
                  <td className="px-3 py-3"><Badge tone={statusTone(item.status)}>{displayStatus(item.status)}</Badge></td>
                  <td className="px-5 py-3"><div className="flex justify-end gap-1">
                    {item.status === 'DRAFT' && <Can perm="religious.khutbah.review"><Button size="sm" variant="outline" onClick={() => transitionMutation.mutate({ id: item.id, status: 'REVIEW' })}>Ajukan review</Button></Can>}
                    {item.status === 'REVIEW' && <Can perm="religious.khutbah.approve"><Button size="sm" onClick={() => transitionMutation.mutate({ id: item.id, status: 'APPROVED' })}><Check className="h-4 w-4" /> Setujui</Button></Can>}
                    {['APPROVED', 'SCHEDULED'].includes(item.status ?? '') && <Can perm="religious.khutbah.deliver"><Button size="sm" variant="outline" onClick={() => transitionMutation.mutate({ id: item.id, status: 'DELIVERED' })}>Tandai disampaikan</Button></Can>}
                  </div></td>
                </tr>)}</tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <Modal open={fridayFormOpen} onClose={() => setFridayFormOpen(false)} title="Jadwalkan shalat Jumat" footer={<><Button variant="outline" onClick={() => setFridayFormOpen(false)}>Batal</Button><Button type="submit" form="religious-friday-form" loading={scheduleMutation.isPending}>Simpan jadwal</Button></>}>
        <form id="religious-friday-form" onSubmit={submitFriday} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Masjid">
            {can('religious.mosque.view') ? <Select value={fridayForm.mosque_id} onChange={(event) => setFridayForm({ ...fridayForm, mosque_id: event.target.value })} required><option value="">Pilih masjid</option>{(mosqueQuery.data ?? []).map((mosque) => <option key={mosque.id} value={mosque.id}>{mosque.name}</option>)}</Select> : <Input type="number" min="1" value={fridayForm.mosque_id} onChange={(event) => setFridayForm({ ...fridayForm, mosque_id: event.target.value })} required />}
          </FormField>
          <FormField label="Tanggal Jumat"><Input type="date" value={fridayForm.prayer_date} onChange={(event) => setFridayForm({ ...fridayForm, prayer_date: event.target.value })} required /></FormField>
          <FormField label="Topik khutbah"><Input value={fridayForm.topic} onChange={(event) => setFridayForm({ ...fridayForm, topic: event.target.value })} /></FormField>
          <FormField label="Tema"><Input value={fridayForm.theme} onChange={(event) => setFridayForm({ ...fridayForm, theme: event.target.value })} /></FormField>
          <FormField label="Waktu mulai"><Input type="time" value={fridayForm.start_time} onChange={(event) => setFridayForm({ ...fridayForm, start_time: event.target.value })} /></FormField>
          <FormField label="Waktu selesai"><Input type="time" value={fridayForm.end_time} onChange={(event) => setFridayForm({ ...fridayForm, end_time: event.target.value })} /></FormField>
          <FormField label="Perkiraan jamaah"><Input type="number" min="0" value={fridayForm.estimated_attendance} onChange={(event) => setFridayForm({ ...fridayForm, estimated_attendance: event.target.value })} /></FormField>
        </form>
      </Modal>

      <Modal open={khutbahFormOpen} onClose={() => setKhutbahFormOpen(false)} title="Buat draf khutbah" size="lg" footer={<><Button variant="outline" onClick={() => setKhutbahFormOpen(false)}>Batal</Button><Button type="submit" form="religious-khutbah-form" loading={createKhutbahMutation.isPending}>Simpan draf</Button></>}>
        <form id="religious-khutbah-form" onSubmit={submitKhutbah} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Judul" className="sm:col-span-2"><Input value={khutbahForm.title} onChange={(event) => setKhutbahForm({ ...khutbahForm, title: event.target.value })} required /></FormField>
          <FormField label="Tema"><Input value={khutbahForm.theme} onChange={(event) => setKhutbahForm({ ...khutbahForm, theme: event.target.value })} /></FormField>
          <FormField label="Kategori"><Select value={khutbahForm.category_code} onChange={(event) => setKhutbahForm({ ...khutbahForm, category_code: event.target.value })}>{khutbahCategories.map((category) => <option key={category.code} value={category.code}>{category.label}</option>)}</Select></FormField>
          <FormField label="Nama khatib"><Input value={khutbahForm.khatib_name} onChange={(event) => setKhutbahForm({ ...khutbahForm, khatib_name: event.target.value })} /></FormField>
          <FormField label="Tanggal Jumat"><Input type="date" value={khutbahForm.prayer_date} onChange={(event) => setKhutbahForm({ ...khutbahForm, prayer_date: event.target.value })} /></FormField>
          <FormField label="Bahasa"><Select value={khutbahForm.language} onChange={(event) => setKhutbahForm({ ...khutbahForm, language: event.target.value })}><option value="id">Bahasa Indonesia</option><option value="ar">Arab</option><option value="en">English</option></Select></FormField>
          <FormField label="Durasi (menit)"><Input type="number" min="1" value={khutbahForm.duration_minutes} onChange={(event) => setKhutbahForm({ ...khutbahForm, duration_minutes: event.target.value })} /></FormField>
          <FormField label="Ringkasan" className="sm:col-span-2"><Textarea value={khutbahForm.summary} onChange={(event) => setKhutbahForm({ ...khutbahForm, summary: event.target.value })} /></FormField>
          <FormField label="Isi khutbah" className="sm:col-span-2"><Textarea rows={7} value={khutbahForm.content} onChange={(event) => setKhutbahForm({ ...khutbahForm, content: event.target.value })} /></FormField>
        </form>
      </Modal>

      <Modal open={attendanceId !== null} onClose={() => setAttendanceId(null)} title="Catat kehadiran jamaah" footer={<><Button variant="outline" onClick={() => setAttendanceId(null)}>Batal</Button><Button type="submit" form="religious-friday-attendance-form" loading={attendanceMutation.isPending}>Simpan kehadiran</Button></>}>
        <form id="religious-friday-attendance-form" onSubmit={submitAttendance} className="space-y-4"><FormField label="Jumlah jamaah aktual"><Input type="number" min="0" value={actualAttendance} onChange={(event) => setActualAttendance(event.target.value)} required /></FormField><p className="text-xs leading-5 text-slate-500">Catatan ini memperbarui realisasi kehadiran pada sesi Jumat.</p></form>
      </Modal>
    </div>
  );
}
