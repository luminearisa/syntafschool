import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, CalendarPlus, DoorClosed, DoorOpen, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { useLocationOptions, FormField } from '../components/AssetUi';
import { facilitiesApi } from '../api/assetFacilityApi';
import { fmtDateTime, fmtDuration, toDateTimeInput } from '../lib/format';
import { facilityStatus } from '../lib/labels';
import type { Facility } from '../types';
import { SearchSelect } from '@/components/ui/SearchSelect';

/**
 * Daftar fasilitas (ruang, gedung, lapangan).
 *
 * Menutup fasilitas bukan sekadar menandai: sekolah perlu tahu siapa saja yang
 * pesanannya terdampak, dan mereka perlu diberi tahu.
 */
/**
 * Daya tampung datang dalam dua bentuk: angka tunggal, atau rincian
 * {total, normal, exam, event}. Keduanya diterima — jangan pernah merender
 * objek apa adanya ke dalam JSX (itu penyebab satu-satunya layar putih pada
 * pengujian pertama modul ini).
 */
function capacityLabel(
  capacity: number | { total?: number | null; normal?: number | null; exam?: number | null; event?: number | null } | null | undefined,
  fallback?: number | null,
): string {
  if (typeof capacity === 'number') return String(capacity);
  if (capacity && typeof capacity === 'object') {
    const total = capacity.total ?? capacity.normal ?? null;
    return total !== null ? String(total) : '-';
  }
  return fallback !== null && fallback !== undefined ? String(fallback) : '-';
}

export default function FacilityListPage() {
  const [bukaBuat, setBukaBuat] = useState(false);
  const [tutup, setTutup] = useState<Facility | null>(null);
  const [hasilTutup, setHasilTutup] = useState<{ affected: { booking_number: string; title: string; start_at: string }[] } | null>(null);
  const [pesan, setPesan] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'facilities', pesan],
    queryFn: () => facilitiesApi.list({ search: pesan, per_page: 30 }),
  });

  const qc = useQueryClient();
  const bukaKembali = useMutation({
    mutationFn: (id: number) => facilitiesApi.reopen(id, {}),
    onSuccess: () => {
      toast.success('Fasilitas dibuka kembali');
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Fasilitas</h1>
          <p className="text-xs text-slate-500">
            Kapasitas, jam operasional, dan aturan booking yang menjaga ruang tidak dipesan dua kali.
          </p>
        </div>
        <Can perm="facility.manage">
          <Button size="sm" onClick={() => setBukaBuat(true)}>
            <Building2 className="mr-1 h-4 w-4" /> Tambah fasilitas
          </Button>
        </Can>
      </header>

      <Card className="p-4">
        <Input placeholder="Cari nama / kode fasilitas…" value={pesan} onChange={(e) => setPesan(e.target.value)} className="max-w-sm" />
      </Card>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        {isLoading ? (
          <div className="col-span-full flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <div className="col-span-full">
            <EmptyState title="Belum ada fasilitas" description="Tambahkan ruang kelas, aula, atau lapangan agar dapat dipesan." />
          </div>
        ) : (
          rows.map((facility) => (
            <Card key={facility.id} className="flex flex-col p-4">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-semibold text-slate-800">{facility.name}</p>
                  <p className="font-mono text-[11px] text-slate-500">{facility.facility_code}</p>
                </div>
                <Badge tone={facilityStatus.tone(facility.status)}>{facilityStatus.label(facility.status)}</Badge>
              </div>

              <div className="mt-3 space-y-1 text-xs text-slate-600">
                <p className="flex items-center gap-1.5">
                  <Users className="h-3.5 w-3.5 text-slate-400" />
                  Daya tampung {capacityLabel(facility.capacity, facility.capacity_total)} orang
                </p>
                <p>Lokasi: {facility.location?.name ?? '-'}</p>
                {facility.booking?.min_booking_minutes && (
                  <p>
                    Booking {fmtDuration(facility.booking.min_booking_minutes)}–{fmtDuration(facility.booking.max_booking_minutes ?? null)}, maksimal{' '}
                    {facility.booking.advance_booking_days ?? '-'} hari ke depan
                  </p>
                )}
                {facility.booking?.requires_approval ? (
                  <p className="text-amber-700">Perlu persetujuan pengelola</p>
                ) : (
                  <p className="text-green-700">Dapat dipesan langsung</p>
                )}
                {facility.booking_count !== null && facility.booking_count !== undefined && (
                  <p>{facility.booking_count} booking tercatat</p>
                )}
              </div>

              <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                <Can perm="facility.view">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      window.location.href = `/assets/facilities/${facility.id}/booking`;
                    }}
                  >
                    <CalendarPlus className="mr-1 h-3.5 w-3.5" /> Pesan
                  </Button>
                </Can>
                <Can perm="facility.manage">
                  {facility.status === 'CLOSED' || facility.status === 'MAINTENANCE' ? (
                    <Button size="sm" variant="ghost" onClick={() => bukaKembali.mutate(facility.id)}>
                      <DoorOpen className="mr-1 h-3.5 w-3.5" /> Buka kembali
                    </Button>
                  ) : (
                    <Button size="sm" variant="ghost" onClick={() => setTutup(facility)}>
                      <DoorClosed className="mr-1 h-3.5 w-3.5" /> Tutup sementara
                    </Button>
                  )}
                </Can>
              </div>
            </Card>
          ))
        )}
      </div>

      {bukaBuat && <FacilityFormModal onClose={() => setBukaBuat(false)} />}
      {tutup && (
        <CloseFacilityModal
          facility={tutup}
          onClose={() => setTutup(null)}
          onDone={(affected) => {
            setTutup(null);
            setHasilTutup({ affected });
          }}
        />
      )}
      {hasilTutup && (
        <Modal open onClose={() => setHasilTutup(null)} title="Fasilitas ditutup">
          <p className="text-sm text-slate-700">
            {hasilTutup.affected.length === 0
              ? 'Tidak ada booking yang terdampak.'
              : `${hasilTutup.affected.length} booking terdampak dan pemesannya telah diberi tahu:`}
          </p>
          <ul className="mt-2 space-y-1 text-xs text-slate-600">
            {hasilTutup.affected.map((b) => (
              <li key={b.booking_number}>
                <span className="font-mono">{b.booking_number}</span> — {b.title} ({fmtDateTime(b.start_at)})
              </li>
            ))}
          </ul>
        </Modal>
      )}
    </div>
  );
}

function FacilityFormModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const { options: locationOptions } = useLocationOptions();
  const [form, setForm] = useState({
    name: '',
    location_id: '' as number | '',
    capacity_total: '',
    capacity_exam: '',
    capacity_event: '',
    min_booking_minutes: '30',
    max_booking_minutes: '240',
    advance_booking_days: '30',
    requires_approval: true,
    description: '',
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const simpan = useMutation({
    mutationFn: () =>
      facilitiesApi.create({
        ...form,
        location_id: form.location_id === '' ? undefined : form.location_id,
        capacity_total: form.capacity_total === '' ? undefined : Number(form.capacity_total),
        capacity_exam: form.capacity_exam === '' ? undefined : Number(form.capacity_exam),
        capacity_event: form.capacity_event === '' ? undefined : Number(form.capacity_event),
        min_booking_minutes: Number(form.min_booking_minutes),
        max_booking_minutes: Number(form.max_booking_minutes),
        advance_booking_days: Number(form.advance_booking_days),
      }),
    onSuccess: () => {
      toast.success('Fasilitas ditambahkan');
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
      title="Tambah fasilitas"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || !form.name}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Nama fasilitas" required error={errors.name?.[0]}>
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Misal: Aula Utama" />
        </FormField>
        <FormField label="Lokasi" error={errors.location_id?.[0]}>
          <SearchSelect options={locationOptions} value={form.location_id} onChange={(v) => setForm({ ...form, location_id: v === '' ? '' : Number(v) })} />
        </FormField>
        <FormField label="Daya tampung total">
          <Input type="number" value={form.capacity_total} onChange={(e) => setForm({ ...form, capacity_total: e.target.value })} />
        </FormField>
        <FormField label="Kapasitas ujian">
          <Input type="number" value={form.capacity_exam} onChange={(e) => setForm({ ...form, capacity_exam: e.target.value })} />
        </FormField>
        <FormField label="Kapasitas acara">
          <Input type="number" value={form.capacity_event} onChange={(e) => setForm({ ...form, capacity_event: e.target.value })} />
        </FormField>
        <FormField label="Durasi minimum (menit)">
          <Input type="number" value={form.min_booking_minutes} onChange={(e) => setForm({ ...form, min_booking_minutes: e.target.value })} />
        </FormField>
        <FormField label="Durasi maksimum (menit)">
          <Input type="number" value={form.max_booking_minutes} onChange={(e) => setForm({ ...form, max_booking_minutes: e.target.value })} />
        </FormField>
        <FormField label="Maksimal pemesanan ke depan (hari)">
          <Input type="number" value={form.advance_booking_days} onChange={(e) => setForm({ ...form, advance_booking_days: e.target.value })} />
        </FormField>
        <FormField label="Perlu persetujuan">
          <Select value={form.requires_approval ? '1' : '0'} onChange={(e) => setForm({ ...form, requires_approval: e.target.value === '1' })}>
            <option value="1">Ya — pesanan harus disetujui</option>
            <option value="0">Tidak — langsung tersimpan</option>
          </Select>
        </FormField>
        <FormField label="Keterangan" className="sm:col-span-2">
          <Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </FormField>
      </div>
    </Modal>
  );
}

function CloseFacilityModal({
  facility,
  onClose,
  onDone,
}: {
  facility: Facility;
  onClose: () => void;
  onDone: (affected: { booking_number: string; title: string; start_at: string }[]) => void;
}) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');
  const [dari, setDari] = useState(toDateTimeInput(new Date()));
  const [sampai, setSampai] = useState(toDateTimeInput(new Date(Date.now() + 7 * 86_400_000)));

  const simpan = useMutation({
    mutationFn: () => facilitiesApi.close(facility.id, { reason, from: dari, to: sampai }),
    onSuccess: (hasil) => {
      toast.success('Fasilitas ditutup');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onDone(hasil.affected ?? []);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Tutup ${facility.name}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" onClick={() => simpan.mutate()} disabled={simpan.isPending || !reason.trim()}>
            Tutup fasilitas
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Alasan" required hint="Alasan tampil pada pemberitahuan pemesan yang terdampak.">
          <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
        <FormField label="Dari">
          <Input type="datetime-local" value={dari} onChange={(e) => setDari(e.target.value)} />
        </FormField>
        <FormField label="Sampai">
          <Input type="datetime-local" value={sampai} onChange={(e) => setSampai(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}
