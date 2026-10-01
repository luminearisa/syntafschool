import { useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, ChevronLeft, ChevronRight, Plus, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { bookingsApi, facilitiesApi } from '../api/assetFacilityApi';
import { FormField } from '../components/AssetUi';
import { addDays, fmtDayLabel, fmtTime, sameDay, startOfWeek, toDateTimeInput } from '../lib/format';
import { bookingStatus, facilityStatus } from '../lib/labels';
import type { FacilityBooking } from '../types';

/**
 * Papan jadwal fasilitas — satu pekan sekaligus.
 *
 * Dipilih papan/pekan, bukan daftar datar, karena pertanyaan nyata pengguna
 * adalah “ruang ini kosong kapan?” — pertanyaan yang tidak terjawab oleh
 * tabel berisi status.
 */
export default function FacilityBookingPage() {
  const { facilityId } = useParams();
  const qc = useQueryClient();
  const [minggu, setMinggu] = useState(() => startOfWeek(new Date()));
  const [bukaPesan, setBukaPesan] = useState(false);
  const [detail, setDetail] = useState<FacilityBooking | null>(null);
  const [tolak, setTolak] = useState<FacilityBooking | null>(null);

  const id = Number(facilityId);
  const dari = minggu.toISOString();
  const sampai = addDays(minggu, 7).toISOString();

  const { data: facility } = useQuery({
    queryKey: ['asset', 'facility', id],
    queryFn: () => facilitiesApi.show(id),
    enabled: Number.isFinite(id),
  });

  const { data: bookings, isLoading } = useQuery({
    queryKey: ['asset', 'bookings', id, dari],
    queryFn: () => bookingsApi.list({ facility_id: id, from: dari, to: sampai, per_page: 100 }),
    enabled: Number.isFinite(id),
  });

  const hari = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(minggu, i)), [minggu]);
  const rows = bookings?.data ?? [];

  const aksi = useMutation({
    mutationFn: ({ booking, langkah }: { booking: FacilityBooking; langkah: 'approve' | 'checkin' | 'complete' | 'cancel' }) => {
      switch (langkah) {
        case 'approve':
          return bookingsApi.approve(booking.id, {});
        case 'checkin':
          return bookingsApi.checkIn(booking.id);
        case 'complete':
          return bookingsApi.complete(booking.id, {});
        default:
          return bookingsApi.cancel(booking.id, { reason: 'Dibatalkan pengelola' });
      }
    },
    onSuccess: () => {
      toast.success('Booking diperbarui');
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (!Number.isFinite(id)) {
    return <EmptyState title="Fasilitas tidak dikenali" />;
  }

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Jadwal {facility?.name ?? 'Fasilitas'}</h1>
          <p className="text-xs text-slate-500">
            {facility?.status ? (
              <>
                Status: {facilityStatus.label(facility.status)}
                {facility.status === 'CLOSED' || facility.status === 'MAINTENANCE' ? ' — pesanan baru ditolak selama penutupan.' : ''}
              </>
            ) : (
              'Pekan berjalan dan pesanan mendatang.'
            )}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="icon" onClick={() => setMinggu((m) => addDays(m, -7))} aria-label="Pekan sebelumnya">
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <Button variant="outline" size="sm" onClick={() => setMinggu(startOfWeek(new Date()))}>
            <CalendarDays className="mr-1 h-4 w-4" /> Pekan ini
          </Button>
          <Button variant="outline" size="icon" onClick={() => setMinggu((m) => addDays(m, 7))} aria-label="Pekan berikutnya">
            <ChevronRight className="h-4 w-4" />
          </Button>
          <Can perm="facility.booking.create">
            <Button size="sm" onClick={() => setBukaPesan(true)}>
              <Plus className="mr-1 h-4 w-4" /> Pesan ruang
            </Button>
          </Can>
        </div>
      </header>

      <Card className="overflow-x-auto">
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : (
          <div className="grid min-w-[900px] grid-cols-7 divide-x divide-slate-100">
            {hari.map((tanggal) => {
              const pesanan = rows
                .filter((b) => sameDay(new Date(b.start_at), tanggal))
                .sort((a, b) => new Date(a.start_at).getTime() - new Date(b.start_at).getTime());

              return (
                <div key={tanggal.toISOString()} className="min-h-[14rem] p-2">
                  <p className={`mb-2 text-center text-[11px] font-medium ${sameDay(tanggal, new Date()) ? 'text-brand-700' : 'text-slate-500'}`}>
                    {fmtDayLabel(tanggal)}
                  </p>
                  <div className="space-y-1.5">
                    {pesanan.length === 0 ? (
                      <p className="text-center text-[10px] text-slate-300">kosong</p>
                    ) : (
                      pesanan.map((booking) => (
                        <button
                          key={booking.id}
                          type="button"
                          onClick={() => setDetail(booking)}
                          className="w-full rounded-md border border-slate-200 bg-slate-50 p-1.5 text-left transition hover:border-brand-300 hover:bg-brand-50"
                        >
                          <p className="text-[11px] font-medium leading-tight text-slate-800">{booking.title}</p>
                          <p className="text-[10px] text-slate-500">
                            {fmtTime(booking.start_at)}–{fmtTime(booking.end_at)}
                          </p>
                          <Badge tone={bookingStatus.tone(booking.status)}>{bookingStatus.label(booking.status)}</Badge>
                        </button>
                      ))
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>

      <Card className="p-4">
        <h2 className="text-sm font-semibold text-slate-800">Pesanan perlu tindakan</h2>
        <ul className="mt-2 divide-y divide-slate-100">
          {rows.filter((b) => ['REQUESTED', 'RESERVED', 'IN_USE'].includes(b.status)).length === 0 ? (
            <li className="py-3 text-xs text-slate-500">Tidak ada pesanan yang menunggu.</li>
          ) : (
            rows
              .filter((b) => ['REQUESTED', 'RESERVED', 'IN_USE'].includes(b.status))
              .map((booking) => (
                <li key={booking.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                  <div>
                    <p className="text-sm text-slate-800">{booking.title}</p>
                    <p className="text-[11px] text-slate-500">
                      <span className="font-mono">{booking.booking_number}</span> · {fmtTime(booking.start_at)}–{fmtTime(booking.end_at)}
                      {booking.requested_by && <> · {booking.requested_by.name}</>}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={bookingStatus.tone(booking.status)}>{bookingStatus.label(booking.status)}</Badge>
                    {booking.status === 'REQUESTED' && (
                      <Can perm="facility.booking.approve">
                        <Button size="sm" onClick={() => aksi.mutate({ booking, langkah: 'approve' })}>
                          Setujui
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setTolak(booking)}>
                          Tolak
                        </Button>
                      </Can>
                    )}
                    {booking.status === 'RESERVED' && (
                      <Can perm="facility.booking.approve">
                        <Button size="sm" variant="outline" onClick={() => aksi.mutate({ booking, langkah: 'checkin' })}>
                          Check-in
                        </Button>
                      </Can>
                    )}
                    {booking.status === 'IN_USE' && (
                      <Can perm="facility.booking.approve">
                        <Button size="sm" variant="outline" onClick={() => aksi.mutate({ booking, langkah: 'complete' })}>
                          Selesaikan
                        </Button>
                      </Can>
                    )}
                    {['REQUESTED', 'RESERVED'].includes(booking.status) && (
                      <Can perm="facility.booking.approve">
                        <Button size="sm" variant="ghost" onClick={() => aksi.mutate({ booking, langkah: 'cancel' })}>
                          <XCircle className="mr-1 h-3.5 w-3.5" /> Batalkan
                        </Button>
                      </Can>
                    )}
                  </div>
                </li>
              ))
          )}
        </ul>
      </Card>

      {bukaPesan && <BookModal facilityId={id} facilityName={facility?.name ?? ''} onClose={() => setBukaPesan(false)} />}
      {tolak && <RejectModal booking={tolak} onClose={() => setTolak(null)} />}
      {detail && (
        <Modal open onClose={() => setDetail(null)} title={`${detail.title} — ${detail.booking_number}`}>
          <div className="space-y-1 text-sm text-slate-700">
            <p>
              {fmtDayLabel(new Date(detail.start_at))} {fmtTime(detail.start_at)}–{fmtTime(detail.end_at)}
            </p>
            <p>Pemesan: {detail.requested_by?.name ?? '-'}</p>
            <p>Peserta: {detail.attendee_count ?? '-'} orang</p>
            <p>Status: {bookingStatus.label(detail.status)}</p>
            {detail.purpose && <p className="text-xs text-slate-600">{detail.purpose}</p>}
            {detail.notes && <p className="text-xs text-slate-600">{detail.notes}</p>}
          </div>
        </Modal>
      )}
    </div>
  );
}

function BookModal({ facilityId, facilityName, onClose }: { facilityId: number; facilityName: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    title: '',
    purpose: '',
    attendee_count: '',
    start_at: toDateTimeInput(new Date(Date.now() + 86_400_000)),
    end_at: toDateTimeInput(new Date(Date.now() + 86_400_000 + 3_600_000)),
    notes: '',
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const simpan = useMutation({
    mutationFn: () => facilitiesApi.book(facilityId, { ...form, attendee_count: form.attendee_count === '' ? undefined : Number(form.attendee_count) }),
    onSuccess: () => {
      toast.success('Pesanan dicatat — jadwal diperiksa ulang saat menyimpan');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Pesan ${facilityName}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || !form.title}>
            Pesan
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Nama kegiatan" required error={errors.title?.[0]}>
          <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        </FormField>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField label="Mulai" required error={errors.start_at?.[0]}>
            <Input type="datetime-local" value={form.start_at} onChange={(e) => setForm({ ...form, start_at: e.target.value })} />
          </FormField>
          <FormField label="Selesai" required error={errors.end_at?.[0]}>
            <Input type="datetime-local" value={form.end_at} onChange={(e) => setForm({ ...form, end_at: e.target.value })} />
          </FormField>
        </div>
        <FormField label="Jumlah peserta" error={errors.attendee_count?.[0]}>
          <Input type="number" value={form.attendee_count} onChange={(e) => setForm({ ...form, attendee_count: e.target.value })} />
        </FormField>
        <FormField label="Keperluan" error={errors.purpose?.[0]}>
          <Textarea rows={2} value={form.purpose} onChange={(e) => setForm({ ...form, purpose: e.target.value })} />
        </FormField>
        <FormField label="Catatan">
          <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>
      </div>
    </Modal>
  );
}

function RejectModal({ booking, onClose }: { booking: FacilityBooking; onClose: () => void }) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');

  const simpan = useMutation({
    mutationFn: () => bookingsApi.reject(booking.id, { reason }),
    onSuccess: () => {
      toast.success('Pesanan ditolak');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Tolak ${booking.booking_number}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" onClick={() => simpan.mutate()} disabled={simpan.isPending || !reason.trim()}>
            Tolak
          </Button>
        </div>
      }
    >
      <FormField label="Alasan penolakan" required hint="Alasan dikirim ke pemesan — bukan sekadar status.">
        <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
