import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { transportationApi } from '../api/transportationApi';
import { DataTable, DateTime, Panel, Stat, StatusPill } from '../components/TmsUi';
import type { IncidentRow } from '../types';

/**
 * Insiden (§45-§49): pelaporan, klasifikasi keparahan, eskalasi, tindakan,
 * resolusi, dan penutupan. Insiden CRITICAL otomatis dieskalasi oleh backend.
 */
export default function TransportationIncidentsPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ incident_type: 'ACCIDENT', severity: 'MEDIUM', trip_id: '', description: '', passengers_involved: 'true' });
  const [resolution, setResolution] = useState('');
  const [selected, setSelected] = useState<number | null>(null);

  const incidents = useQuery({ queryKey: ['transportation', 'incidents'], queryFn: () => transportationApi.incidents({}) });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['transportation', 'incidents'] });

  const report = useMutation({
    mutationFn: () => transportationApi.reportIncident({
      incident_type: form.incident_type,
      severity: form.severity,
      trip_id: form.trip_id ? Number(form.trip_id) : undefined,
      description: form.description || 'Insiden dilaporkan dari UI.',
      passengers_involved: form.passengers_involved === 'true',
    }),
    onSuccess: () => { toast.success('Insiden dilaporkan — dispatcher & wali diberi tahu'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Laporan insiden gagal')),
  });

  const acknowledge = useMutation({
    mutationFn: (id: number) => transportationApi.acknowledgeIncident(id),
    onSuccess: () => { toast.success('Insiden ditindaklanjuti'); refresh(); },
  });

  const resolve = useMutation({
    mutationFn: (id: number) => transportationApi.resolveIncident(id, { resolution: resolution || 'Ditangani petugas transportasi.' }),
    onSuccess: () => { toast.success('Insiden diselesaikan'); refresh(); },
  });

  const close = useMutation({
    mutationFn: (id: number) => transportationApi.closeIncident(id),
    onSuccess: () => { toast.success('Insiden ditutup'); refresh(); },
  });

  const emergency = useMutation({
    mutationFn: ({ tripId, vehicleId }: { tripId: number; vehicleId: number }) =>
      transportationApi.breakdown(tripId, { replacement_vehicle_id: vehicleId, reason: 'Kendaraan mogok (UI)', severity: 'HIGH' }),
    onSuccess: (result) => {
      const transferred = (result as { passengers_transferred?: number }).passengers_transferred ?? 0;
      toast.success(`Kendaraan pengganti dikirim · ${transferred} penumpang dipindahkan`);
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Penanganan mogok gagal')),
  });

  const rows = incidents.data ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Insiden transportasi</h1>
        <p className="text-sm text-slate-500">Alur: dilaporkan → ditindaklanjuti → diselesaikan → ditinjau → ditutup.</p>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Terbuka" value={rows.filter((row) => !['CLOSED', 'RESOLVED'].includes(row.status)).length} tone="warn" />
        <Stat label="Kritis" value={rows.filter((row) => row.severity === 'CRITICAL').length} tone="bad" />
        <Stat label="Dieskalasi" value={rows.filter((row) => row.escalated).length} />
        <Stat label="Wali diberi tahu" value={rows.filter((row) => row.parent_notified).length} tone="good" />
      </div>

      <Panel title="Daftar insiden" description="Klik baris untuk memilih insiden lalu jalankan tindakan">
        <DataTable<IncidentRow>
          loading={incidents.isLoading}
          rows={rows}
          empty="Tidak ada insiden tercatat."
          columns={[
            { key: 'number', header: 'Insiden', render: (row) => (
              <button type="button" className="text-left" onClick={() => setSelected(row.id)}>
                <span className="font-medium text-slate-800">{row.incident_number}</span>
                <span className="block text-xs text-slate-500">{row.incident_type} · trip #{row.trip_id ?? '—'}</span>
              </button>
            ) },
            { key: 'severity', header: 'Keparahan', render: (row) => <StatusPill status={row.severity} /> },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
            { key: 'when', header: 'Waktu', render: (row) => <DateTime value={row.occurred_at} /> },
            { key: 'desc', header: 'Keterangan', render: (row) => <span className="text-xs text-slate-600">{row.description.slice(0, 70)}</span> },
            { key: 'flags', header: 'Penanda', render: (row) => (
              <div className="flex flex-wrap gap-1">
                {row.escalated ? <StatusPill status="CRITICAL" label="eskalasi" /> : null}
                {row.passengers_involved ? <StatusPill status="MEDIUM" label="penumpang" /> : null}
                {row.parent_notified ? <StatusPill status="ACTIVE" label="wali" /> : null}
              </div>
            ) },
          ]}
        />
      </Panel>

      {selected ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel title="Tindakan insiden" description="Setiap tindakan tersimpan sebagai riwayat">
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" onClick={() => acknowledge.mutate(selected)}>Tindak lanjuti</Button>
              <Button variant="ghost" onClick={() => close.mutate(selected)}>Tutup insiden</Button>
            </div>
            <Input className="mt-3" placeholder="Catatan resolusi" value={resolution} onChange={(event) => setResolution(event.target.value)} />
            <Button className="mt-2" onClick={() => resolve.mutate(selected)}>Simpan resolusi</Button>

            {(() => {
              const incident = rows.find((row) => row.id === selected);
              if (!incident?.emergency_actions) return null;
              return (
                <div className="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
                  <p className="font-medium">Rencana darurat</p>
                  <ul className="mt-1 list-disc pl-4">
                    {(incident.emergency_actions.steps ?? []).map((step) => <li key={step}>{step}</li>)}
                  </ul>
                  <p className="mt-2 font-medium">Kontak</p>
                  <p>{(incident.emergency_actions.contacts ?? []).map((contact) => `${contact.label}${contact.phone ? ` (${contact.phone})` : ''}`).join(' · ')}</p>
                </div>
              );
            })()}
          </Panel>

          <Panel title="Laporkan insiden baru" description="Pengemudi juga dapat melaporkan dari aplikasi pengemudi">
            <div className="grid gap-2 sm:grid-cols-2">
              <Select value={form.incident_type} onChange={(event) => setForm({ ...form, incident_type: event.target.value })}>
                {['ACCIDENT', 'BREAKDOWN', 'MEDICAL', 'STUDENT_BEHAVIOR', 'MISSING_PASSENGER', 'ROAD_BLOCK', 'SECURITY', 'SAFETY', 'DELAY', 'OTHER'].map((type) => (
                  <option key={type} value={type}>{type}</option>
                ))}
              </Select>
              <Select value={form.severity} onChange={(event) => setForm({ ...form, severity: event.target.value })}>
                {['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map((severity) => <option key={severity} value={severity}>{severity}</option>)}
              </Select>
              <Input placeholder="ID trip (opsional)" value={form.trip_id} onChange={(event) => setForm({ ...form, trip_id: event.target.value })} />
              <Select value={form.passengers_involved} onChange={(event) => setForm({ ...form, passengers_involved: event.target.value })}>
                <option value="true">Penumpang terlibat</option>
                <option value="false">Tanpa penumpang</option>
              </Select>
            </div>
            <Input className="mt-2" placeholder="Kronologi singkat" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} />
            <Button className="mt-3" onClick={() => report.mutate()} loading={report.isPending}>Kirim laporan</Button>

            {can('transportation.trip.dispatch') ? (
              <div className="mt-4 border-t border-slate-200 pt-3">
                <p className="text-xs font-medium text-slate-500">Alur mogok kendaraan (§49)</p>
                <div className="mt-2 grid gap-2 sm:grid-cols-3">
                  <Input id="breakdown-trip" placeholder="ID trip" />
                  <Input id="breakdown-vehicle" placeholder="ID kendaraan pengganti" />
                  <Button variant="danger" onClick={() => {
                    const tripId = Number((document.getElementById('breakdown-trip') as HTMLInputElement)?.value);
                    const vehicleId = Number((document.getElementById('breakdown-vehicle') as HTMLInputElement)?.value);
                    if (!tripId || !vehicleId) { toast.error('Isi ID trip dan kendaraan pengganti'); return; }
                    emergency.mutate({ tripId, vehicleId });
                  }}>Kirim kendaraan pengganti</Button>
                </div>
              </div>
            ) : null}
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
