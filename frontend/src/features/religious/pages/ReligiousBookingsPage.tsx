import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Plus, RefreshCw, X } from 'lucide-react';
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
import { religiousApi } from '../api/religiousApi';
import { displayStatus, ErrorCard, FormField, formatDateTime, LoadingCard, ReligiousPageHeader, statusTone } from '../components/ReligiousUi';

interface BookingForm {
  mosque_id: string;
  area_id: string;
  title: string;
  purpose: string;
  booking_type: string;
  requester_name: string;
  contact_phone: string;
  starts_at: string;
  ends_at: string;
  expected_attendees: string;
  require_approval: boolean;
  notes: string;
}
const blankBooking = (): BookingForm => ({
  mosque_id: '', area_id: '', title: '', purpose: '', booking_type: 'INTERNAL', requester_name: '',
  contact_phone: '', starts_at: '', ends_at: '', expected_attendees: '', require_approval: true, notes: '',
});

export function ReligiousBookingsPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [status, setStatus] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState<BookingForm>(blankBooking);
  const [rejectId, setRejectId] = useState<number | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const bookingsQuery = useQuery({
    queryKey: ['religious', 'bookings', status, from, to],
    queryFn: () => religiousApi.bookings({ status: status || undefined, from: from || undefined, to: to || undefined }),
  });
  const mosqueQuery = useQuery({
    queryKey: ['religious', 'mosques', 'booking-options'],
    queryFn: () => religiousApi.listMosques({}),
    enabled: can('religious.mosque.view'),
  });

  const createMutation = useMutation({
    mutationFn: () => religiousApi.createBooking({
      mosque_id: Number(form.mosque_id),
      area_id: form.area_id ? Number(form.area_id) : undefined,
      title: form.title.trim(),
      purpose: form.purpose.trim() || undefined,
      booking_type: form.booking_type,
      requester_name: form.requester_name.trim() || undefined,
      contact_phone: form.contact_phone.trim() || undefined,
      starts_at: form.starts_at.replace('T', ' '),
      ends_at: form.ends_at.replace('T', ' '),
      expected_attendees: form.expected_attendees ? Number(form.expected_attendees) : undefined,
      require_approval: form.require_approval,
      notes: form.notes.trim() || undefined,
    }),
    onSuccess: () => {
      toast.success('Permintaan pemesanan masjid dibuat');
      setFormOpen(false);
      setForm(blankBooking());
      queryClient.invalidateQueries({ queryKey: ['religious', 'bookings'] });
      queryClient.invalidateQueries({ queryKey: ['religious', 'mosques'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const approveMutation = useMutation({
    mutationFn: (id: number) => religiousApi.approveBooking(id),
    onSuccess: () => {
      toast.success('Pemesanan disetujui');
      queryClient.invalidateQueries({ queryKey: ['religious', 'bookings'] });
      queryClient.invalidateQueries({ queryKey: ['religious', 'mosques'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const rejectMutation = useMutation({
    mutationFn: () => religiousApi.rejectBooking(rejectId!, rejectReason.trim()),
    onSuccess: () => {
      toast.success('Pemesanan ditolak');
      setRejectId(null);
      setRejectReason('');
      queryClient.invalidateQueries({ queryKey: ['religious', 'bookings'] });
      queryClient.invalidateQueries({ queryKey: ['religious', 'mosques'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const submitBooking = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!form.mosque_id || !form.title.trim() || !form.starts_at || !form.ends_at) {
      toast.error('Masjid, judul, waktu mulai, dan waktu selesai wajib diisi.');
      return;
    }
    if (form.ends_at <= form.starts_at) {
      toast.error('Waktu selesai harus setelah waktu mulai.');
      return;
    }
    createMutation.mutate();
  };
  const submitReject = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (rejectReason.trim().length < 3) {
      toast.error('Alasan penolakan wajib diisi.');
      return;
    }
    rejectMutation.mutate();
  };

  const rows = bookingsQuery.data ?? [];

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Pemesanan masjid"
        description="Ajukan, tinjau, dan setujui pemakaian masjid. Konflik waktu diperiksa backend terhadap pemesanan dan sesi kegiatan."
        actions={
          <>
            <Button variant="outline" size="icon" aria-label="Muat ulang pemesanan" title="Muat ulang" onClick={() => bookingsQuery.refetch()}>
              <RefreshCw className={bookingsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="religious.booking.request"><Button onClick={() => { setForm(blankBooking()); setFormOpen(true); }}><Plus className="h-4 w-4" /> Ajukan pemesanan</Button></Can>
          </>
        }
      />

      <Card>
        <CardBody className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
          <FormField label="Status">
            <Select value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">Semua status</option>
              {['REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED'].map((item) => <option key={item} value={item}>{displayStatus(item)}</option>)}
            </Select>
          </FormField>
          <FormField label="Dari tanggal"><Input type="date" value={from} onChange={(event) => setFrom(event.target.value)} /></FormField>
          <FormField label="Sampai tanggal"><Input type="date" value={to} onChange={(event) => setTo(event.target.value)} /></FormField>
        </CardBody>
      </Card>

      {bookingsQuery.isLoading ? <LoadingCard label="Memuat pemesanan..." /> : bookingsQuery.isError ? <ErrorCard message={getErrorMessage(bookingsQuery.error)} onRetry={() => bookingsQuery.refetch()} /> : rows.length === 0 ? <Card><EmptyState title="Belum ada pemesanan" description="Permintaan baru akan muncul di sini untuk diproses." /></Card> : (
        <Card>
          <CardHeader><CardTitle className="text-sm">Daftar pemesanan ({rows.length})</CardTitle></CardHeader>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[850px] text-sm">
              <thead><tr className="border-y border-slate-100 bg-slate-50/70 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-5 py-3 font-medium">Kegiatan</th><th className="px-3 py-3 font-medium">Masjid / area</th><th className="px-3 py-3 font-medium">Waktu</th><th className="px-3 py-3 text-right font-medium">Perkiraan</th><th className="px-3 py-3 font-medium">Status</th><th className="px-5 py-3 text-right font-medium">Tindakan</th>
              </tr></thead>
              <tbody>{rows.map((booking) => (
                <tr key={booking.id} className="border-b border-slate-50 last:border-0">
                  <td className="px-5 py-3"><p className="font-medium text-slate-800">{booking.title}</p><p className="mt-0.5 text-xs text-slate-500">{booking.code ?? `ID ${booking.id}`}</p></td>
                  <td className="px-3 py-3 text-slate-600">#{booking.mosque_id}{booking.area_id ? ` / area ${booking.area_id}` : ''}</td>
                  <td className="px-3 py-3 text-xs text-slate-600"><span className="block">{formatDateTime(booking.starts_at)}</span><span className="mt-0.5 block">s.d. {formatDateTime(booking.ends_at)}</span></td>
                  <td className="px-3 py-3 text-right text-slate-700">{booking.expected_attendees ?? '—'}</td>
                  <td className="px-3 py-3"><Badge tone={statusTone(booking.status)}>{displayStatus(booking.status)}</Badge></td>
                  <td className="px-5 py-3">
                    {booking.status === 'REQUESTED' && can('religious.booking.approve') ? (
                      <div className="flex justify-end gap-2">
                        <Button size="sm" onClick={() => approveMutation.mutate(booking.id)} loading={approveMutation.isPending}><Check className="h-4 w-4" /> Setujui</Button>
                        <Button size="sm" variant="danger" onClick={() => { setRejectId(booking.id); setRejectReason(''); }}><X className="h-4 w-4" /> Tolak</Button>
                      </div>
                    ) : <span className="block text-right text-xs text-slate-400">—</span>}
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        </Card>
      )}

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title="Ajukan pemesanan masjid" size="lg" footer={<><Button variant="outline" onClick={() => setFormOpen(false)}>Batal</Button><Button type="submit" form="religious-booking-form" loading={createMutation.isPending}>Kirim permintaan</Button></>}>
        <form id="religious-booking-form" onSubmit={submitBooking} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {can('religious.mosque.view') ? (
            <FormField label="Masjid">
              <Select value={form.mosque_id} onChange={(event) => setForm({ ...form, mosque_id: event.target.value })} required>
                <option value="">Pilih masjid</option>
                {(mosqueQuery.data ?? []).map((mosque) => <option key={mosque.id} value={mosque.id}>{mosque.name}</option>)}
              </Select>
            </FormField>
          ) : <FormField label="ID masjid"><Input type="number" min="1" value={form.mosque_id} onChange={(event) => setForm({ ...form, mosque_id: event.target.value })} required /></FormField>}
          <FormField label="ID area (opsional)"><Input type="number" min="1" value={form.area_id} onChange={(event) => setForm({ ...form, area_id: event.target.value })} placeholder="Area tertentu" /></FormField>
          <FormField label="Judul kegiatan"><Input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} required /></FormField>
          <FormField label="Jenis pemesanan">
            <Select value={form.booking_type} onChange={(event) => setForm({ ...form, booking_type: event.target.value })}><option value="INTERNAL">Internal</option><option value="PUBLIC">Umum</option><option value="EVENT">Acara</option></Select>
          </FormField>
          <FormField label="Pemohon"><Input value={form.requester_name} onChange={(event) => setForm({ ...form, requester_name: event.target.value })} /></FormField>
          <FormField label="Nomor kontak"><Input type="tel" value={form.contact_phone} onChange={(event) => setForm({ ...form, contact_phone: event.target.value })} /></FormField>
          <FormField label="Mulai"><Input type="datetime-local" value={form.starts_at} onChange={(event) => setForm({ ...form, starts_at: event.target.value })} required /></FormField>
          <FormField label="Selesai"><Input type="datetime-local" value={form.ends_at} onChange={(event) => setForm({ ...form, ends_at: event.target.value })} required /></FormField>
          <FormField label="Perkiraan jamaah"><Input type="number" min="0" value={form.expected_attendees} onChange={(event) => setForm({ ...form, expected_attendees: event.target.value })} /></FormField>
          <FormField label="Tujuan"><Input value={form.purpose} onChange={(event) => setForm({ ...form, purpose: event.target.value })} placeholder="Kajian, rapat, kegiatan siswa..." /></FormField>
          <FormField label="Catatan" className="sm:col-span-2"><Textarea value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} /></FormField>
          <label className="flex items-start gap-2 text-sm text-slate-700 sm:col-span-2"><input type="checkbox" checked={form.require_approval} onChange={(event) => setForm({ ...form, require_approval: event.target.checked })} className="mt-0.5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600" /> Minta persetujuan melalui alur kerja bila tersedia</label>
        </form>
      </Modal>

      <Modal open={rejectId !== null} onClose={() => setRejectId(null)} title="Tolak pemesanan" footer={<><Button variant="outline" onClick={() => setRejectId(null)}>Batal</Button><Button variant="danger" type="submit" form="religious-booking-reject-form" loading={rejectMutation.isPending}>Tolak permintaan</Button></>}>
        <form id="religious-booking-reject-form" onSubmit={submitReject} className="space-y-3">
          <FormField label="Alasan penolakan"><Textarea value={rejectReason} onChange={(event) => setRejectReason(event.target.value)} required minLength={3} maxLength={1000} /></FormField>
          <p className="text-xs text-slate-500">Alasan ini disimpan bersama keputusan pemesanan.</p>
        </form>
      </Modal>
    </div>
  );
}
