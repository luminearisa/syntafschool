import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { transportationApi } from '../api/transportationApi';
import { DataTable, Panel, Stat, StatusPill } from '../components/TmsUi';
import type { LostFoundRow } from '../types';

/** Barang hilang & temuan (§71): catat → identifikasi → kabari wali → kembalikan. */
export default function TransportationLostFoundPage() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ item_name: '', description: '', vehicle_id: '', trip_id: '', found_location: '' });
  const [returnName, setReturnName] = useState('');

  const items = useQuery({ queryKey: ['transportation', 'lost-found'], queryFn: () => transportationApi.lostFound({}) });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['transportation', 'lost-found'] });

  const record = useMutation({
    mutationFn: () => transportationApi.recordLostFound({
      item_name: form.item_name,
      description: form.description || undefined,
      vehicle_id: form.vehicle_id ? Number(form.vehicle_id) : undefined,
      trip_id: form.trip_id ? Number(form.trip_id) : undefined,
      found_location: form.found_location || undefined,
    }),
    onSuccess: () => { toast.success('Barang temuan dicatat'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Pencatatan gagal')),
  });

  const returnItem = useMutation({
    mutationFn: (id: number) => transportationApi.returnLostFound(id, { returned_to_name: returnName || 'Wali siswa' }),
    onSuccess: () => { toast.success('Barang dikembalikan & tercatat'); refresh(); },
  });

  const rows = items.data ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Barang hilang & temuan</h1>
        <p className="text-sm text-slate-500">Temuan pasca-trip otomatis masuk ke daftar ini lengkap dengan kendaraan & trip.</p>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Ditemukan" value={rows.filter((row) => row.status === 'FOUND').length} tone="warn" />
        <Stat label="Sudah dikabari" value={rows.filter((row) => row.owner_notified).length} />
        <Stat label="Dikembalikan" value={rows.filter((row) => row.status === 'RETURNED').length} tone="good" />
        <Stat label="Total" value={rows.length} />
      </div>

      <Panel title="Daftar temuan">
        <DataTable<LostFoundRow>
          loading={items.isLoading}
          rows={rows}
          empty="Belum ada barang temuan."
          columns={[
            { key: 'code', header: 'Kode', render: (row) => row.item_code },
            { key: 'item', header: 'Barang', render: (row) => (
              <div>
                <p className="font-medium text-slate-800">{row.item_name}</p>
                <p className="text-xs text-slate-500">kendaraan #{row.vehicle_id ?? '—'} · trip #{row.trip_id ?? '—'}</p>
              </div>
            ) },
            { key: 'found', header: 'Ditemukan', render: (row) => row.found_date ?? '—' },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
            { key: 'owner', header: 'Wali', render: (row) => row.owner_notified ? <StatusPill status="SENT" label="dikabari" /> : '—' },
            { key: 'actions', header: '', render: (row) => row.status !== 'RETURNED' ? (
              <div className="flex flex-wrap gap-1">
                <Button size="sm" variant="outline" onClick={() => transportationApi.lostFound({}).then(() => toast.info('Gunakan ID siswa pada endpoint identifikasi bila perlu'))}>Identifikasi</Button>
                <Button size="sm" onClick={() => returnItem.mutate(row.id)}>Kembalikan</Button>
              </div>
            ) : '—' },
          ]}
        />
      </Panel>

      <Panel title="Catat temuan baru" description="Diisi petugas saat serah terima kendaraan setelah trip">
        <div className="grid gap-2 sm:grid-cols-3">
          <Input placeholder="Nama barang" value={form.item_name} onChange={(event) => setForm({ ...form, item_name: event.target.value })} />
          <Input placeholder="Keterangan" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} />
          <Input placeholder="ID kendaraan" value={form.vehicle_id} onChange={(event) => setForm({ ...form, vehicle_id: event.target.value })} />
          <Input placeholder="ID trip" value={form.trip_id} onChange={(event) => setForm({ ...form, trip_id: event.target.value })} />
          <Input placeholder="Lokasi temuan (mis. bangku 3)" value={form.found_location} onChange={(event) => setForm({ ...form, found_location: event.target.value })} />
          <Input placeholder="Nama penerima saat dikembalikan" value={returnName} onChange={(event) => setReturnName(event.target.value)} />
        </div>
        <Button className="mt-3" onClick={() => record.mutate()} loading={record.isPending}>Simpan temuan</Button>
      </Panel>
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
