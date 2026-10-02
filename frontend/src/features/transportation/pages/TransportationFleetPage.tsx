import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { transportationApi } from '../api/transportationApi';
import { DataTable, Panel, Progress, Stat, StatusPill } from '../components/TmsUi';
import type { Vehicle } from '../types';

/**
 * Armada: kendaraan, kapasitas, dokumen, perlengkapan, BBM, dan inspeksi.
 * Kendaraan tetap milik Asset Management — profil di sini hanya menambah
 * atribut operasional transportasi (§6).
 */
export default function TransportationFleetPage() {
  const can = useAuthStore((state) => state.can);
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'vehicles';
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ vehicle_code: '', asset_id: '', vehicle_type: 'BUS', registration_number: '', student_capacity: '40', reserved_capacity: '0' });
  const [fuelForm, setFuelForm] = useState({ vehicle_id: '', fuel_quantity: '40', fuel_cost: '540000', odometer: '' });
  const [documentForm, setDocumentForm] = useState({ vehicle_id: '', document_type: 'STNK', document_number: '', expiry_date: '' });
  const [statusReason, setStatusReason] = useState('');

  const vehicles = useQuery({ queryKey: ['transportation', 'vehicles'], queryFn: () => transportationApi.vehicles() });
  const documents = useQuery({ queryKey: ['transportation', 'documents'], queryFn: () => transportationApi.expiringDocuments({ days: 60 }) });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['transportation', 'vehicles'] });

  const createVehicle = useMutation({
    mutationFn: () => transportationApi.createVehicle({
      vehicle_code: form.vehicle_code || undefined,
      asset_id: form.asset_id ? Number(form.asset_id) : undefined,
      vehicle_type: form.vehicle_type,
      registration_number: form.registration_number || undefined,
      student_capacity: Number(form.student_capacity),
      total_capacity: Number(form.student_capacity),
      reserved_capacity: Number(form.reserved_capacity),
    }),
    onSuccess: () => { toast.success('Kendaraan ditambahkan'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menambah kendaraan')),
  });

  const logFuel = useMutation({
    mutationFn: () => transportationApi.logFuel({
      vehicle_id: Number(fuelForm.vehicle_id),
      fuel_quantity: Number(fuelForm.fuel_quantity),
      fuel_cost: Number(fuelForm.fuel_cost),
      odometer: fuelForm.odometer ? Number(fuelForm.odometer) : undefined,
    }),
    onSuccess: (result) => {
      const efficiency = (result as { result?: { efficiency_km_per_liter?: number } }).result?.efficiency_km_per_liter;
      toast.success(`Pengisian tercatat${efficiency ? ` · ${efficiency} km/liter` : ''}`);
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Pencatatan BBM gagal')),
  });

  const changeStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => transportationApi.setVehicleStatus(id, { status, reason: statusReason || undefined }),
    onSuccess: () => { toast.success('Status kendaraan diperbarui'); refresh(); },
  });

  const addDocument = useMutation({
    mutationFn: () => transportationApi.addVehicleDocument(Number(documentForm.vehicle_id), {
      document_type: documentForm.document_type,
      document_number: documentForm.document_number || undefined,
      expiry_date: documentForm.expiry_date || undefined,
    }),
    onSuccess: () => { toast.success('Dokumen dicatat — verifikasi diperlukan sebelum dipakai dispatch'); refresh(); },
  });

  const rows = vehicles.data ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Armada</h1>
        <p className="text-sm text-slate-500">Kesiapan kendaraan: status, dokumen, perlengkapan, pemeliharaan, BBM.</p>
      </div>

      <div className="flex flex-wrap gap-2">
        {[['vehicles', 'Kendaraan'], ['documents', 'Dokumen'], ['fuel', 'BBM'], ['inspections', 'Inspeksi']].map(([key, label]) => (
          <button key={key} type="button" onClick={() => { params.set('tab', key); setParams(params, { replace: true }); }}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium ${tab === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'}`}>
            {label}
          </button>
        ))}
      </div>

      {tab === 'vehicles' ? (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Stat label="Total kendaraan" value={rows.length} />
            <Stat label="Siap" value={rows.filter((row) => row.status === 'AVAILABLE').length} tone="good" />
            <Stat label="Pemeliharaan" value={rows.filter((row) => row.maintenance.in_maintenance).length} tone="warn" />
            <Stat label="Perlengkapan kritis bermasalah" value={rows.reduce((total, row) => total + row.equipment.critical_problem, 0)} tone="bad" />
          </div>

          <Panel title="Daftar kendaraan" description="Kapasitas, dokumen, dan status pemeliharaan terbaca langsung">
            <DataTable<Vehicle>
              loading={vehicles.isLoading}
              rows={rows}
              empty="Belum ada kendaraan terdaftar."
              columns={[
                { key: 'code', header: 'Kendaraan', render: (row) => (
                  <div>
                    <p className="font-medium text-slate-800">{row.vehicle_code}</p>
                    <p className="text-xs text-slate-500">{row.vehicle_type} · {row.registration_number ?? 'tanpa nomor'} · {row.ownership_type}</p>
                  </div>
                ) },
                { key: 'capacity', header: 'Kapasitas', render: (row) => <Progress value={row.capacity.reserved_capacity} max={row.capacity.student_capacity} /> },
                { key: 'documents', header: 'Dokumen', render: (row) => (
                  <span className={row.documents.expired > 0 ? 'text-rose-600' : row.documents.expiring_soon > 0 ? 'text-amber-600' : 'text-slate-600'}>
                    {row.documents.total} total · {row.documents.expired} kedaluwarsa
                  </span>
                ) },
                { key: 'equipment', header: 'Perlengkapan', render: (row) => row.equipment.critical_problem > 0
                  ? <span className="text-rose-600">{row.equipment.critical_problem} kritis</span>
                  : <span className="text-slate-600">{row.equipment.total} item</span> },
                { key: 'maintenance', header: 'Pemeliharaan', render: (row) => row.maintenance.in_maintenance
                  ? <StatusPill status="MAINTENANCE" label={`${row.maintenance.open_work_order_count} WO`} />
                  : <span className="text-xs text-slate-500">{row.maintenance.next_maintenance_at ? `berikutnya ${row.maintenance.next_maintenance_at}` : 'tidak ada jadwal'}</span> },
                { key: 'odometer', header: 'Odometer', render: (row) => `${new Intl.NumberFormat('id-ID').format(row.odometer)} km` },
                { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
                { key: 'actions', header: '', render: (row) => can('transportation.fleet.manage') ? (
                  <div className="flex flex-wrap gap-1">
                    <Button size="sm" variant="outline" onClick={() => changeStatus.mutate({ id: row.id, status: row.status === 'MAINTENANCE' ? 'AVAILABLE' : 'MAINTENANCE' })}>
                      {row.status === 'MAINTENANCE' ? 'Selesai servis' : 'Masuk servis'}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => transportationApi.vehicle(row.id).then((detail) => {
                      toast.info(`${row.vehicle_code}: ${JSON.stringify((detail.eligibility as { blockers?: unknown[] })?.blockers ?? []).slice(0, 160)}`);
                    })}>Kelayakan</Button>
                  </div>
                ) : '—' },
              ]}
            />
          </Panel>

          {can('transportation.fleet.manage') ? (
            <Panel title="Tambah kendaraan" description="Kendaraan harus merujuk aset yang sudah ada (§6)">
              <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <Input placeholder="Kode (mis. BUS-03)" value={form.vehicle_code} onChange={(event) => setForm({ ...form, vehicle_code: event.target.value })} />
                <Input placeholder="ID Aset" value={form.asset_id} onChange={(event) => setForm({ ...form, asset_id: event.target.value })} />
                <Select value={form.vehicle_type} onChange={(event) => setForm({ ...form, vehicle_type: event.target.value })}>
                  {['BUS', 'MINIBUS', 'VAN', 'CAR', 'MOTORCYCLE', 'SHUTTLE', 'SPECIAL_VEHICLE'].map((type) => (
                    <option key={type} value={type}>{type}</option>
                  ))}
                </Select>
                <Input placeholder="Nomor registrasi" value={form.registration_number} onChange={(event) => setForm({ ...form, registration_number: event.target.value })} />
                <Input placeholder="Kapasitas siswa" value={form.student_capacity} onChange={(event) => setForm({ ...form, student_capacity: event.target.value })} />
                <Input placeholder="Kursi dicadangkan" value={form.reserved_capacity} onChange={(event) => setForm({ ...form, reserved_capacity: event.target.value })} />
              </div>
              <div className="mt-3 flex items-center gap-2">
                <Button onClick={() => createVehicle.mutate()} loading={createVehicle.isPending}>Simpan kendaraan</Button>
                <Input placeholder="Alasan perubahan status (opsional)" value={statusReason} onChange={(event) => setStatusReason(event.target.value)} className="max-w-xs" />
              </div>
            </Panel>
          ) : null}
        </>
      ) : null}

      {tab === 'documents' ? (
        <Panel title="Dokumen mendekati kedaluwarsa" description="Dokumen pemblokir membuat kendaraan tidak dapat ditugaskan (§10)">
          <DataTable<{ id: number; vehicle_id: number; document_type: string; expiry_date: string; days_left: number; expired: boolean; blocks_dispatch: boolean }>
            loading={documents.isLoading}
            rows={documents.data ?? []}
            empty="Tidak ada dokumen yang mendekati kedaluwarsa."
            columns={[
              { key: 'vehicle', header: 'Kendaraan', render: (row) => `#${row.vehicle_id}` },
              { key: 'type', header: 'Jenis', render: (row) => row.document_type },
              { key: 'expiry', header: 'Kedaluwarsa', render: (row) => row.expiry_date },
              { key: 'days', header: 'Sisa hari', render: (row) => row.expired ? <span className="text-rose-600">terlewat {Math.abs(row.days_left)} hari</span> : `${row.days_left} hari` },
              { key: 'block', header: 'Memblokir', render: (row) => row.blocks_dispatch ? <StatusPill status="HIGH" label="ya" /> : 'tidak' },
            ]}
          />
          {can('transportation.fleet.documents') ? (
            <div className="mt-3 grid gap-2 sm:grid-cols-4">
              <Input placeholder="ID kendaraan" value={documentForm.vehicle_id} onChange={(event) => setDocumentForm({ ...documentForm, vehicle_id: event.target.value })} />
              <Select value={documentForm.document_type} onChange={(event) => setDocumentForm({ ...documentForm, document_type: event.target.value })}>
                {['STNK', 'KIR', 'INSURANCE', 'VEHICLE_REGISTRATION', 'INSPECTION_CERTIFICATE', 'PERMIT', 'OTHER'].map((type) => (
                  <option key={type} value={type}>{type}</option>
                ))}
              </Select>
              <Input placeholder="Nomor dokumen" value={documentForm.document_number} onChange={(event) => setDocumentForm({ ...documentForm, document_number: event.target.value })} />
              <Input type="date" value={documentForm.expiry_date} onChange={(event) => setDocumentForm({ ...documentForm, expiry_date: event.target.value })} />
              <Button onClick={() => addDocument.mutate()} loading={addDocument.isPending}>Catat dokumen</Button>
            </div>
          ) : null}
        </Panel>
      ) : null}

      {tab === 'fuel' ? (
        <Panel title="Catatan bahan bakar" description="Efisiensi dihitung dari selisih odometer, biaya per km otomatis (§58)">
          <div className="grid gap-2 sm:grid-cols-4">
            <Input placeholder="ID kendaraan" value={fuelForm.vehicle_id} onChange={(event) => setFuelForm({ ...fuelForm, vehicle_id: event.target.value })} />
            <Input placeholder="Liter" value={fuelForm.fuel_quantity} onChange={(event) => setFuelForm({ ...fuelForm, fuel_quantity: event.target.value })} />
            <Input placeholder="Biaya (Rp)" value={fuelForm.fuel_cost} onChange={(event) => setFuelForm({ ...fuelForm, fuel_cost: event.target.value })} />
            <Input placeholder="Odometer" value={fuelForm.odometer} onChange={(event) => setFuelForm({ ...fuelForm, odometer: event.target.value })} />
          </div>
          <Button className="mt-3" onClick={() => logFuel.mutate()} loading={logFuel.isPending}>Catat pengisian</Button>
          <div className="mt-4">
            <DataTable<Vehicle>
              rows={rows}
              columns={[
                { key: 'code', header: 'Kendaraan', render: (row) => row.vehicle_code },
                { key: 'odometer', header: 'Odometer', render: (row) => `${new Intl.NumberFormat('id-ID').format(row.odometer)} km` },
                { key: 'trips', header: 'Trip aktif', render: (row) => row.active_trips ?? 0 },
                { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
              ]}
            />
          </div>
        </Panel>
      ) : null}

      {tab === 'inspections' ? (
        <Panel title="Inspeksi kendaraan" description="Inspeksi berkala, temuan, dan biaya per kendaraan">
          <DataTable<Vehicle>
            rows={rows}
            empty="Belum ada kendaraan."
            columns={[
              { key: 'code', header: 'Kendaraan', render: (row) => row.vehicle_code },
              { key: 'next', header: 'Inspeksi berikutnya', render: (row) => row.maintenance.next_maintenance_at ?? '—' },
              { key: 'wo', header: 'Work order terbuka', render: (row) => row.maintenance.open_work_order_count },
              { key: 'equipment', header: 'Perlengkapan bermasalah', render: (row) => row.equipment.problem },
            ]}
          />
          <div className="mt-3 rounded-lg border border-slate-200 p-3 text-xs text-slate-600">
            Checklist pra-trip & pasca-trip dilakukan pengemudi dari aplikasi pengemudi; kegagalan item kritis otomatis memblokir
            keberangkatan dan tercatat sebagai temuan pemeliharaan.
          </div>
        </Panel>
      ) : null}
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
