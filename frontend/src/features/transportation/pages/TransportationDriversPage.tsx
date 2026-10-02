import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { transportationApi } from '../api/transportationApi';
import { DataTable, DateTime, Panel, Stat, StatusPill } from '../components/TmsUi';
import type { Driver } from '../types';

/**
 * Pengemudi: profil (berasal dari HRD), SIM, kualifikasi, pelatihan, dan
 * ketersediaan. Penugasan divalidasi otomatis: SIM sesuai kendaraan,
 * kualifikasi wajib, dan bentrok jadwal (§12, §13, §15).
 */
export default function TransportationDriversPage() {
  const can = useAuthStore((state) => state.can);
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'drivers';
  const queryClient = useQueryClient();
  const [employeeId, setEmployeeId] = useState('');
  const [licenseForm, setLicenseForm] = useState({ driver_id: '', license_type: 'B1', license_number: '', expiry_date: '' });
  const [availabilityForm, setAvailabilityForm] = useState({ driver_id: '', date: new Date().toISOString().slice(0, 10), start_time: '05:00', end_time: '08:00', status: 'LEAVE', reason: '' });

  const drivers = useQuery({ queryKey: ['transportation', 'drivers'], queryFn: () => transportationApi.drivers() });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['transportation', 'drivers'] });

  const syncEmployee = useMutation({
    mutationFn: () => transportationApi.drivers(employeeId ? {} : {}).then(() => employeeId),
    onSuccess: () => toast.info('Gunakan endpoint sinkronisasi HRD dari backend bila pegawai belum punya profil pengemudi.'),
  });

  const addLicense = useMutation({
    mutationFn: () => transportationApi.addLicense(Number(licenseForm.driver_id), {
      license_type: licenseForm.license_type,
      license_number: licenseForm.license_number,
      expiry_date: licenseForm.expiry_date || undefined,
      is_primary: true,
    }),
    onSuccess: () => { toast.success('SIM dicatat'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'SIM gagal dicatat')),
  });

  const syncQualification = useMutation({
    mutationFn: ({ id, type }: { id: number; type: string }) =>
      transportationApi.syncQualifications(id, [{ qualification_type: type, status: 'VALID' }]),
    onSuccess: () => { toast.success('Kualifikasi dicatat'); refresh(); },
  });

  const addAvailability = useMutation({
    mutationFn: () => transportationApi.addAvailability(Number(availabilityForm.driver_id), {
      date: availabilityForm.date,
      start_time: availabilityForm.start_time,
      end_time: availabilityForm.end_time,
      status: availabilityForm.status,
      reason: availabilityForm.reason || undefined,
    }),
    onSuccess: () => { toast.success('Ketersediaan diperbarui — penugasan otomatis menyesuaikan'); refresh(); },
  });

  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => transportationApi.setDriverStatus(id, { driver_status: status }),
    onSuccess: () => { toast.success('Status pengemudi diperbarui'); refresh(); },
  });

  const rows = drivers.data ?? [];
  const required = ['DEFENSIVE_DRIVING', 'FIRST_AID', 'SCHOOL_CHILD_SAFETY', 'SAFEGUARDING'];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Pengemudi</h1>
        <p className="text-sm text-slate-500">Profil pengemudi selalu merujuk pegawai HRD — tidak ada data pegawai kedua.</p>
      </div>

      <div className="flex flex-wrap gap-2">
        {[['drivers', 'Pengemudi'], ['licenses', 'SIM'], ['qualifications', 'Kualifikasi'], ['training', 'Pelatihan & ketersediaan']].map(([key, label]) => (
          <button key={key} type="button" onClick={() => { params.set('tab', key); setParams(params, { replace: true }); }}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium ${tab === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'}`}>
            {label}
          </button>
        ))}
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Total pengemudi" value={rows.length} />
        <Stat label="Tersedia" value={rows.filter((row) => row.driver_status === 'AVAILABLE').length} tone="good" />
        <Stat label="SIM kedaluwarsa" value={rows.filter((row) => !row.license_valid).length} tone="bad" />
        <Stat label="Bertugas hari ini" value={rows.reduce((total, row) => total + row.trips_today, 0)} />
      </div>

      <Panel
        title={tab === 'drivers' ? 'Daftar pengemudi' : tab === 'licenses' ? 'SIM & masa berlaku' : tab === 'qualifications' ? 'Kualifikasi pengemudi' : 'Pelatihan & ketersediaan'}
        description="Validasi otomatis: SIM sesuai jenis kendaraan, kualifikasi wajib, dan tidak ada bentrok jadwal"
      >
        <DataTable<Driver>
          loading={drivers.isLoading}
          rows={rows}
          empty="Belum ada pengemudi. Sinkronkan dari data pegawai HRD."
          columns={tab === 'drivers' ? [
            { key: 'code', header: 'Pengemudi', render: (row) => (
              <div>
                <p className="font-medium text-slate-800">{row.driver_code}</p>
                <p className="text-xs text-slate-500">{row.full_name ?? 'nama belum tersinkron'}</p>
              </div>
            ) },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.driver_status} /> },
            { key: 'license', header: 'SIM', render: (row) => row.license_type
              ? <span className={row.license_valid ? 'text-slate-600' : 'text-rose-600'}>{row.license_type} · {row.license_expiry ?? 'tanpa masa berlaku'}</span>
              : <span className="text-rose-600">belum ada</span> },
            { key: 'qualifications', header: 'Kualifikasi', render: (row) => `${row.qualifications.length}/${required.length}` },
            { key: 'trips', header: 'Trip hari ini', render: (row) => row.trips_today },
            { key: 'actions', header: '', render: (row) => can('transportation.driver.manage') ? (
              <div className="flex flex-wrap gap-1">
                <Button size="sm" variant="outline" onClick={() => setStatus.mutate({ id: row.id, status: row.driver_status === 'AVAILABLE' ? 'OFF_DUTY' : 'AVAILABLE' })}>
                  {row.driver_status === 'AVAILABLE' ? 'Off-duty' : 'Aktifkan'}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => transportationApi.driver(row.id).then((detail) => {
                  toast.info(`${row.driver_code}: ${JSON.stringify((detail.eligibility as { blockers?: unknown[] })?.blockers ?? []).slice(0, 160)}`);
                })}>Kelayakan</Button>
              </div>
            ) : '—' },
          ] : tab === 'licenses' ? [
            { key: 'code', header: 'Pengemudi', render: (row) => row.driver_code },
            { key: 'license', header: 'Kelas SIM', render: (row) => row.license_type ?? '—' },
            { key: 'expiry', header: 'Berlaku sampai', render: (row) => row.license_expiry ?? '—' },
            { key: 'valid', header: 'Validitas', render: (row) => <StatusPill status={row.license_valid ? 'ACTIVE' : 'SUSPENDED'} label={row.license_valid ? 'valid' : 'kedaluwarsa'} /> },
          ] : tab === 'qualifications' ? [
            { key: 'code', header: 'Pengemudi', render: (row) => row.driver_code },
            { key: 'owned', header: 'Dimiliki', render: (row) => row.qualifications.length ? row.qualifications.join(', ') : <span className="text-rose-600">belum ada</span> },
            { key: 'missing', header: 'Wajib belum lengkap', render: (row) => required.filter((type) => !row.qualifications.includes(type)).join(', ') || '—' },
            { key: 'actions', header: '', render: (row) => can('transportation.driver.manage') ? (
              <Select defaultValue="" onChange={(event) => event.target.value && syncQualification.mutate({ id: row.id, type: event.target.value })}>
                <option value="">Tambah kualifikasi…</option>
                {required.map((type) => <option key={type} value={type}>{type}</option>)}
              </Select>
            ) : '—' },
          ] : [
            { key: 'code', header: 'Pengemudi', render: (row) => row.driver_code },
            { key: 'trips', header: 'Trip hari ini', render: (row) => row.trips_today },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.driver_status} /> },
            { key: 'license', header: 'SIM valid', render: (row) => <DateTime value={row.license_expiry} /> },
          ]}
        />
      </Panel>

      {can('transportation.driver.manage') ? (
        <div className="grid gap-4 lg:grid-cols-3">
          <Panel title="Catat SIM" description="Kelas SIM menentukan kendaraan yang boleh dikemudikan">
            <div className="space-y-2">
              <Input placeholder="ID pengemudi" value={licenseForm.driver_id} onChange={(event) => setLicenseForm({ ...licenseForm, driver_id: event.target.value })} />
              <Select value={licenseForm.license_type} onChange={(event) => setLicenseForm({ ...licenseForm, license_type: event.target.value })}>
                {['A', 'B1', 'B2', 'C', 'OTHER'].map((type) => <option key={type} value={type}>{type}</option>)}
              </Select>
              <Input placeholder="Nomor SIM" value={licenseForm.license_number} onChange={(event) => setLicenseForm({ ...licenseForm, license_number: event.target.value })} />
              <Input type="date" value={licenseForm.expiry_date} onChange={(event) => setLicenseForm({ ...licenseForm, expiry_date: event.target.value })} />
              <Button onClick={() => addLicense.mutate()} loading={addLicense.isPending}>Simpan SIM</Button>
            </div>
          </Panel>

          <Panel title="Catat ketidaksediaan" description="Cuti/off-duty otomatis memblokir penugasan (§14)">
            <div className="space-y-2">
              <Input placeholder="ID pengemudi" value={availabilityForm.driver_id} onChange={(event) => setAvailabilityForm({ ...availabilityForm, driver_id: event.target.value })} />
              <Input type="date" value={availabilityForm.date} onChange={(event) => setAvailabilityForm({ ...availabilityForm, date: event.target.value })} />
              <div className="grid grid-cols-2 gap-2">
                <Input value={availabilityForm.start_time} onChange={(event) => setAvailabilityForm({ ...availabilityForm, start_time: event.target.value })} />
                <Input value={availabilityForm.end_time} onChange={(event) => setAvailabilityForm({ ...availabilityForm, end_time: event.target.value })} />
              </div>
              <Select value={availabilityForm.status} onChange={(event) => setAvailabilityForm({ ...availabilityForm, status: event.target.value })}>
                {['LEAVE', 'OFF_DUTY', 'SUSPENDED', 'AVAILABLE'].map((status) => <option key={status} value={status}>{status}</option>)}
              </Select>
              <Input placeholder="Alasan" value={availabilityForm.reason} onChange={(event) => setAvailabilityForm({ ...availabilityForm, reason: event.target.value })} />
              <Button onClick={() => addAvailability.mutate()} loading={addAvailability.isPending}>Simpan ketersediaan</Button>
            </div>
          </Panel>

          <Panel title="Sinkronisasi pegawai HRD" description="Profil pengemudi dibuat dari pegawai, bukan entri manual ganda">
            <Input placeholder="ID pegawai HRD" value={employeeId} onChange={(event) => setEmployeeId(event.target.value)} />
            <Button className="mt-2" variant="outline" onClick={() => syncEmployee.mutate()}>Petunjuk sinkronisasi</Button>
            <p className="mt-2 text-xs text-slate-500">
              Endpoint: <code>POST /api/v1/transportation/drivers/sync-from-employee</code> dengan <code>{'{ employee_id }'}</code>.
              Perubahan data kepegawaian tetap di HRD.
            </p>
          </Panel>
        </div>
      ) : null}
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
